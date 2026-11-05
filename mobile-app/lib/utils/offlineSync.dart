import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:http/http.dart' as http;
import 'package:path/path.dart' as path;
import 'package:sqflite/sqflite.dart';
import 'package:sqflite_common/sqlite_api.dart' show DatabaseFactory, OpenDatabaseOptions;
import 'package:uuid/uuid.dart';

typedef AccessTokenProvider = Future<String?> Function();
typedef ConnectivityCheck = Future<bool> Function();

class OfflineOperation {
  const OfflineOperation({
    required this.id,
    required this.operation,
    required this.state,
    required this.payload,
    this.serverEntityId,
    this.attemptCount = 0,
    this.lastError,
  });

  final String id;
  final String operation;
  final String state;
  final Map<String, dynamic> payload;
  final String? serverEntityId;
  final int attemptCount;
  final String? lastError;

  factory OfflineOperation.fromRow(Map<String, Object?> row) {
    return OfflineOperation(
      id: row['id']! as String,
      operation: row['operation']! as String,
      state: row['state']! as String,
      payload: jsonDecode(row['payload_json']! as String) as Map<String, dynamic>,
      serverEntityId: row['server_entity_id'] as String?,
      attemptCount: row['attempt_count'] as int? ?? 0,
      lastError: row['last_error'] as String?,
    );
  }
}

class SyncReport {
  const SyncReport({required this.synced, required this.pending, required this.needsAttention});

  final int synced;
  final int pending;
  final int needsAttention;
}

class OfflineSyncException implements Exception {
  const OfflineSyncException(this.message, {this.statusCode});

  final String message;
  final int? statusCode;

  @override
  String toString() => message;
}

class OfflineSync {
  OfflineSync({
    required this.apiBaseUrl,
    required this.accountId,
    required this.accessTokenProvider,
    Connectivity? connectivity,
    http.Client? httpClient,
    DatabaseFactory? localDatabaseFactory,
    String? localDatabasePath,
    ConnectivityCheck? connectivityCheck,
    Stream<bool>? connectivityChanges,
    this.requestTimeout = const Duration(seconds: 20),
  })  : _connectivity = connectivity ?? Connectivity(),
        _httpClient = httpClient ?? http.Client(),
        _localDatabaseFactory = localDatabaseFactory,
        _localDatabasePath = localDatabasePath,
        _connectivityCheck = connectivityCheck,
        _connectivityChanges = connectivityChanges;

  final String apiBaseUrl;
  final String accountId;
  final AccessTokenProvider accessTokenProvider;
  final Connectivity _connectivity;
  final http.Client _httpClient;
  final DatabaseFactory? _localDatabaseFactory;
  final String? _localDatabasePath;
  final ConnectivityCheck? _connectivityCheck;
  final Stream<bool>? _connectivityChanges;
  final Duration requestTimeout;
  final Uuid _uuid = const Uuid();

  Database? _database;
  StreamSubscription<dynamic>? _connectivitySubscription;
  Timer? _retryTimer;
  DateTime? _retryAt;
  bool _syncing = false;
  bool _disposed = false;

  Future<void> initialize() async {
    if (_database != null) return;
    final database = _localDatabaseFactory ?? databaseFactory;
    final databasePath = _localDatabasePath ??
        (_localDatabaseFactory == null
            ? path.join(await getDatabasesPath(), 'farmlink_offline.db')
            : ':memory:');
    _database = await database.openDatabase(
      databasePath,
      options: OpenDatabaseOptions(
        version: 2,
        onCreate: (db, version) async {
          await db.execute('''
            CREATE TABLE offline_operations (
              id TEXT PRIMARY KEY,
              account_id TEXT NOT NULL,
              operation TEXT NOT NULL,
              local_entity_id TEXT NOT NULL,
              payload_json TEXT NOT NULL,
              idempotency_key TEXT NOT NULL,
              state TEXT NOT NULL,
              server_entity_id TEXT,
              attempt_count INTEGER NOT NULL DEFAULT 0,
              next_retry_at INTEGER,
              last_error TEXT,
              created_at INTEGER NOT NULL,
              UNIQUE(account_id, idempotency_key)
            )
          ''');
          await db.execute(
            'CREATE INDEX offline_operations_queue ON offline_operations(account_id, state, created_at)',
          );
          await db.execute('''
            CREATE TABLE app_preferences (
              preference_key TEXT PRIMARY KEY,
              preference_value TEXT NOT NULL
            )
          ''');
        },
        onUpgrade: (db, oldVersion, newVersion) async {
          if (oldVersion < 2) {
            await db.execute('ALTER TABLE offline_operations ADD COLUMN server_entity_id TEXT');
            await db.execute('''
              CREATE TABLE app_preferences (
                preference_key TEXT PRIMARY KEY,
                preference_value TEXT NOT NULL
              )
            ''');
          }
        },
      ),
    );
    final changes = _connectivityChanges;
    if (changes != null) {
      _connectivitySubscription = changes.listen((online) {
        if (online) unawaited(_runBackgroundSync());
      });
    } else if (_connectivityCheck == null) {
      _connectivitySubscription = _connectivity.onConnectivityChanged.listen((results) {
        if (_hasConnection(results)) {
          unawaited(_runBackgroundSync());
        }
      });
    }
    unawaited(_runBackgroundSync());
  }

  Future<String> createListingDraft(Map<String, dynamic> listing) async {
    await _ensureInitialized();
    _validateListing(listing);
    final operationId = _uuid.v4();
    final now = DateTime.now().toUtc().millisecondsSinceEpoch;
    await _database!.insert('offline_operations', {
      'id': operationId,
      'account_id': accountId,
      'operation': 'create_product',
      'local_entity_id': operationId,
      'payload_json': jsonEncode(listing),
      'idempotency_key': _uuid.v4(),
      'state': 'pending',
      'created_at': now,
    });

    if (await _isOnline()) {
      await syncPending();
    }
    return operationId;
  }

  Future<String> saveBidDraft({
    required String productId,
    required num quantity,
    required int unitPriceMinor,
    String? message,
  }) async {
    await _ensureInitialized();
    if (productId.isEmpty || quantity <= 0 || unitPriceMinor <= 0 || quantity * 1000 != (quantity * 1000).round()) {
      throw const OfflineSyncException('Provide a product, positive quantity with at most three decimals, and a positive price.');
    }
    if ((message?.length ?? 0) > 1000) {
      throw const OfflineSyncException('The offer message cannot exceed 1000 characters.');
    }

    final operationId = _uuid.v4();
    final payload = <String, dynamic>{
      'product_id': productId,
      'quantity': quantity,
      'unit_price_minor': unitPriceMinor,
      if (message != null && message.trim().isNotEmpty) 'message': message.trim(),
    };
    await _database!.insert('offline_operations', {
      'id': operationId,
      'account_id': accountId,
      'operation': 'bid_draft',
      'local_entity_id': productId,
      'payload_json': jsonEncode(payload),
      'idempotency_key': _uuid.v4(),
      'state': 'draft',
      'created_at': DateTime.now().toUtc().millisecondsSinceEpoch,
    });
    return operationId;
  }

  Future<void> updateBidDraft(
    String operationId, {
    required num quantity,
    required int unitPriceMinor,
    String? message,
  }) async {
    await _ensureInitialized();
    final row = await _findOperation(operationId);
    if (row == null || row['operation'] != 'bid_draft' || row['state'] == 'submitted') {
      throw const OfflineSyncException('This offer can no longer be edited.');
    }
    if (quantity <= 0 || quantity * 1000 != (quantity * 1000).round() || unitPriceMinor <= 0) {
      throw const OfflineSyncException('Enter a positive quantity with at most three decimals and a positive price.');
    }
    if ((message?.length ?? 0) > 1000) {
      throw const OfflineSyncException('The offer message cannot exceed 1000 characters.');
    }

    final payload = jsonDecode(row['payload_json']! as String) as Map<String, dynamic>;
    payload['quantity'] = quantity;
    payload['unit_price_minor'] = unitPriceMinor;
    payload['message'] = message?.trim();
    await _database!.update(
      'offline_operations',
      {
        'payload_json': jsonEncode(payload),
        'idempotency_key': _uuid.v4(),
        'state': 'draft',
        'attempt_count': 0,
        'next_retry_at': null,
        'last_error': null,
      },
      where: 'id = ? AND account_id = ?',
      whereArgs: [operationId, accountId],
    );
  }

  Future<String?> getPreference(String key) async {
    await _ensureInitialized();
    final rows = await _database!.query(
      'app_preferences',
      columns: ['preference_value'],
      where: 'preference_key = ?',
      whereArgs: [key],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first['preference_value'] as String;
  }

  Future<void> setPreference(String key, String value) async {
    await _ensureInitialized();
    await _database!.insert(
      'app_preferences',
      {'preference_key': key, 'preference_value': value},
      conflictAlgorithm: ConflictAlgorithm.replace,
    );
  }

  Future<void> retryOperation(String operationId) async {
    await _ensureInitialized();
    final row = await _findOperation(operationId);
    if (row == null || row['operation'] != 'create_product' || row['state'] == 'synced') {
      throw const OfflineSyncException('This listing cannot be retried.');
    }
    await _database!.update(
      'offline_operations',
      {'state': 'pending', 'next_retry_at': null, 'last_error': null},
      where: 'id = ? AND account_id = ?',
      whereArgs: [operationId, accountId],
    );
    if (await _isOnline()) await syncPending();
  }

  Future<void> submitBidDraft(String operationId) async {
    await _ensureInitialized();
    if (!await _isOnline()) {
      throw const OfflineSyncException('Connect to the internet before submitting an offer.');
    }

    final operation = await _findOperation(operationId);
    if (operation == null || operation['operation'] != 'bid_draft') {
      throw const OfflineSyncException('The saved offer draft could not be found.');
    }
    if (operation['account_id'] != accountId) {
      throw const OfflineSyncException('This offer draft belongs to a different account.');
    }

    final payload = jsonDecode(operation['payload_json']! as String) as Map<String, dynamic>;
    final token = await accessTokenProvider();
    if (token == null || token.isEmpty) {
      throw const OfflineSyncException('Sign in again before submitting your offer.');
    }

    try {
      final response = await _httpClient.post(
        _uri('/products/${Uri.encodeComponent(payload['product_id'] as String)}/bids'),
        headers: _headers(token, operation['idempotency_key']! as String),
        body: jsonEncode({
          'quantity': payload['quantity'],
          'unit_price_minor': payload['unit_price_minor'],
          if (payload['message'] != null) 'message': payload['message'],
        }),
      ).timeout(requestTimeout);
      if (response.statusCode < 200 || response.statusCode >= 300) {
        final needsAttention = response.statusCode == 409 || response.statusCode == 422;
        await _updateOperation(
          operationId,
          state: needsAttention ? 'needs_attention' : 'draft',
          error: _serverMessage(response),
        );
        throw OfflineSyncException(_serverMessage(response), statusCode: response.statusCode);
      }

      await _updateOperation(operationId, state: 'submitted');
    } on OfflineSyncException {
      rethrow;
    } on http.ClientException {
      throw const OfflineSyncException('Network error. Your offer remains saved as a draft.');
    } on TimeoutException {
      throw const OfflineSyncException('The request timed out. Check your offer status before retrying.');
    }
  }

  Future<Map<String, dynamic>> acceptBid(String bidId, {required String idempotencyKey}) async {
    await _ensureInitialized();
    if (!await _isOnline()) {
      throw const OfflineSyncException('Bid acceptance requires an active connection and current server state.');
    }
    if (bidId.isEmpty || !_isUuid(idempotencyKey)) {
      throw const OfflineSyncException('A bid ID and stable idempotency UUID are required.');
    }

    final token = await accessTokenProvider();
    if (token == null || token.isEmpty) {
      throw const OfflineSyncException('Sign in again before accepting a bid.');
    }

    final response = await _httpClient
        .post(
          _uri('/bids/${Uri.encodeComponent(bidId)}/accept'),
          headers: _headers(token, idempotencyKey),
        )
        .timeout(requestTimeout);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw OfflineSyncException(_serverMessage(response), statusCode: response.statusCode);
    }

    try {
      return jsonDecode(response.body) as Map<String, dynamic>;
    } on FormatException {
      throw const OfflineSyncException('The server returned an invalid bid acceptance response.');
    }
  }

  Future<SyncReport> syncPending() async {
    await _ensureInitialized();
    if (_syncing || _disposed) {
      return const SyncReport(synced: 0, pending: 0, needsAttention: 0);
    }

    _syncing = true;
    var synced = 0;
    try {
      if (await _isOnline()) {
        final now = DateTime.now().toUtc().millisecondsSinceEpoch;
        final rows = await _database!.query(
          'offline_operations',
          where: 'account_id = ? AND operation = ? AND state = ? AND (next_retry_at IS NULL OR next_retry_at <= ?)',
          whereArgs: [accountId, 'create_product', 'pending', now],
          orderBy: 'created_at ASC',
        );

        for (final row in rows) {
          if (_disposed || !await _isOnline()) break;
          final token = await accessTokenProvider();
          if (token == null || token.isEmpty) {
            await _updateOperation(row['id']! as String, state: 'needs_attention', error: 'Authentication required.');
            continue;
          }

          try {
            final response = await _httpClient
                .post(
                  _uri('/products'),
                  headers: _headers(token, row['idempotency_key']! as String),
                  body: row['payload_json']! as String,
                )
                .timeout(requestTimeout);

            if (response.statusCode >= 200 && response.statusCode < 300) {
              final serverId = _createdEntityId(response);
              if (serverId == null) {
                await _updateOperation(
                  row['id']! as String,
                  state: 'needs_attention',
                  error: 'The server response did not include a listing ID. Check your listings before retrying.',
                );
                continue;
              }
              await _database!.update(
                'offline_operations',
                {
                  'state': 'synced',
                  'server_entity_id': serverId,
                  'last_error': null,
                  'next_retry_at': null,
                },
                where: 'id = ? AND account_id = ?',
                whereArgs: [row['id'], accountId],
              );
              synced++;
            } else if (response.statusCode == 401) {
              await _updateOperation(row['id']! as String, state: 'needs_attention', error: 'Sign in again to sync this listing.');
            } else if (response.statusCode == 409 || response.statusCode == 422 || response.statusCode == 403) {
              await _updateOperation(row['id']! as String, state: 'needs_attention', error: _serverMessage(response));
            } else if (response.statusCode == 429 || response.statusCode >= 500) {
              await _scheduleRetry(row, _serverMessage(response), response.headers['retry-after']);
            } else {
              await _updateOperation(row['id']! as String, state: 'needs_attention', error: _serverMessage(response));
            }
          } on TimeoutException {
            await _scheduleRetry(row, 'Request timed out. The listing will be retried.');
          } on http.ClientException {
            await _scheduleRetry(row, 'Network error. The listing will be retried.');
          }
        }
      }
    } finally {
      _syncing = false;
    }

    final pending = Sqflite.firstIntValue(await _database!.rawQuery(
          "SELECT COUNT(*) FROM offline_operations WHERE account_id = ? AND state = 'pending'",
          [accountId],
        )) ??
        0;
    final needsAttention = Sqflite.firstIntValue(await _database!.rawQuery(
          "SELECT COUNT(*) FROM offline_operations WHERE account_id = ? AND state = 'needs_attention'",
          [accountId],
        )) ??
        0;
    final retryRows = await _database!.rawQuery(
      'SELECT MIN(next_retry_at) AS retry_at FROM offline_operations '
      'WHERE account_id = ? AND state = ? AND next_retry_at IS NOT NULL',
      [accountId, 'pending'],
    );
    final nextRetry = retryRows.first['retry_at'] as int?;
    if (nextRetry != null) {
      _scheduleRetryTimer(DateTime.fromMillisecondsSinceEpoch(nextRetry, isUtc: true));
    }
    return SyncReport(synced: synced, pending: pending, needsAttention: needsAttention);
  }

  Future<List<OfflineOperation>> getOperations() async {
    await _ensureInitialized();
    final rows = await _database!.query(
      'offline_operations',
      where: 'account_id = ? AND state != ?',
      whereArgs: [accountId, 'submitted'],
      orderBy: 'created_at DESC',
    );
    return rows.map(OfflineOperation.fromRow).toList(growable: false);
  }

  Future<List<Map<String, dynamic>>> getMyListings() async {
    await _ensureInitialized();
    if (!await _isOnline()) return const [];
    final token = await accessTokenProvider();
    if (token == null || token.isEmpty) return const [];

    final response = await _httpClient
        .get(_uri('/my/products'), headers: _authHeaders(token))
        .timeout(requestTimeout);
    if (response.statusCode < 200 || response.statusCode >= 300) {
      throw OfflineSyncException(_serverMessage(response), statusCode: response.statusCode);
    }

    try {
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      final items = body['data'];
      if (items is List) {
        return items.whereType<Map<String, dynamic>>().toList(growable: false);
      }
      return const [];
    } on FormatException {
      throw const OfflineSyncException('The server returned an invalid listings response.');
    } on TypeError {
      throw const OfflineSyncException('The server returned an invalid listings response.');
    }
  }

  Future<void> deleteDraft(String operationId) async {
    await _ensureInitialized();
    await _database!.delete(
      'offline_operations',
      where: 'id = ? AND account_id = ? AND state IN (?, ?)',
      whereArgs: [operationId, accountId, 'draft', 'needs_attention'],
    );
  }

  Future<void> dispose() async {
    _disposed = true;
    await _connectivitySubscription?.cancel();
    _retryTimer?.cancel();
    _httpClient.close();
    await _database?.close();
    _database = null;
  }

  Future<void> _ensureInitialized() async {
    if (_database == null) await initialize();
  }

  Future<Map<String, Object?>?> _findOperation(String id) async {
    final rows = await _database!.query(
      'offline_operations',
      where: 'id = ? AND account_id = ?',
      whereArgs: [id, accountId],
      limit: 1,
    );
    return rows.isEmpty ? null : rows.first;
  }

  Future<void> _updateOperation(String id, {required String state, String? error}) async {
    await _database!.update(
      'offline_operations',
      {
        'state': state,
        'last_error': error,
        if (state == 'synced' || state == 'submitted') 'next_retry_at': null,
      },
      where: 'id = ? AND account_id = ?',
      whereArgs: [id, accountId],
    );
  }

  Future<void> _scheduleRetry(Map<String, Object?> row, String error, [String? retryAfter]) async {
    final attempts = (row['attempt_count'] as int) + 1;
    final exponent = attempts < 1 ? 1 : (attempts > 10 ? 10 : attempts);
    final retryAfterSeconds = int.tryParse(retryAfter ?? '');
    final backoffSeconds = (1 << exponent) + (attempts % 7);
    final delaySeconds = retryAfterSeconds == null
        ? backoffSeconds
        : (retryAfterSeconds < 1 ? 1 : (retryAfterSeconds > 86400 ? 86400 : retryAfterSeconds));
    final retryAt = DateTime.now().toUtc().add(Duration(seconds: delaySeconds));
    await _database!.update(
      'offline_operations',
      {
        'attempt_count': attempts,
        'next_retry_at': retryAt.millisecondsSinceEpoch,
        'last_error': error,
      },
      where: 'id = ? AND account_id = ?',
      whereArgs: [row['id'], accountId],
    );
    _scheduleRetryTimer(retryAt);
  }

  void _scheduleRetryTimer(DateTime retryAt) {
    if (_disposed || (_retryAt != null && !retryAt.isBefore(_retryAt!))) return;
    final delay = retryAt.difference(DateTime.now().toUtc());
    if (delay.isNegative) return;
    _retryTimer?.cancel();
    _retryAt = retryAt;
    _retryTimer = Timer(delay, () {
      _retryTimer = null;
      _retryAt = null;
      unawaited(_runBackgroundSync());
    });
  }

  Future<void> _runBackgroundSync() async {
    try {
      await syncPending();
    } catch (error, stackTrace) {
      Zone.current.handleUncaughtError(error, stackTrace);
    }
  }

  Future<bool> _isOnline() async {
    final check = _connectivityCheck;
    return check == null ? _hasConnection(await _connectivity.checkConnectivity()) : check();
  }

  bool _hasConnection(List<ConnectivityResult> results) {
    return results.any((result) => result != ConnectivityResult.none);
  }

  bool _isUuid(String value) {
    return RegExp(r'^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$', caseSensitive: false)
        .hasMatch(value);
  }

  Uri _uri(String route) => Uri.parse('${apiBaseUrl.replaceFirst(RegExp(r'/$'), '')}$route');

  Map<String, String> _headers(String token, String idempotencyKey) => {
        'Accept': 'application/json',
        'Content-Type': 'application/json',
        ..._authHeaders(token),
        'Idempotency-Key': idempotencyKey,
      };

  Map<String, String> _authHeaders(String token) => {
        'Accept': 'application/json',
        'Authorization': 'Bearer $token',
      };

  String? _createdEntityId(http.Response response) {
    try {
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      final data = body['data'];
      if (data is Map<String, dynamic> && data['id'] is String) return data['id'] as String;
      if (body['id'] is String) return body['id'] as String;
    } on FormatException {
      return null;
    } on TypeError {
      return null;
    }
    return null;
  }

  String _serverMessage(http.Response response) {
    try {
      final body = jsonDecode(response.body) as Map<String, dynamic>;
      return body['error']?['message'] as String? ??
          body['message'] as String? ??
          'The server rejected the request (${response.statusCode}).';
    } on FormatException {
      return 'The server rejected the request (${response.statusCode}).';
    } on TypeError {
      return 'The server rejected the request (${response.statusCode}).';
    }
  }

  void _validateListing(Map<String, dynamic> listing) {
    final category = listing['category'];
    if (category != 'produce' && category != 'input') {
      throw const OfflineSyncException('Listing category must be produce or input.');
    }
    if ((listing['name'] as String?)?.trim().isEmpty ?? true) {
      throw const OfflineSyncException('A listing name is required.');
    }
    if (listing['quantity_available'] is! num || (listing['quantity_available'] as num) <= 0) {
      throw const OfflineSyncException('Available quantity must be greater than zero.');
    }
    if (listing['price_minor'] is! int || (listing['price_minor'] as int) <= 0) {
      throw const OfflineSyncException('Price must be a positive integer in minor currency units.');
    }
    if (listing['currency'] != 'KES') {
      throw const OfflineSyncException('M-Pesa checkout currently supports KES listings only.');
    }
    if (listing['unit'] is! String || (listing['unit'] as String).trim().isEmpty) {
      throw const OfflineSyncException('A listing unit is required.');
    }
    if (listing['currency'] is! String || (listing['currency'] as String).length != 3) {
      throw const OfflineSyncException('Currency must be a three-letter code.');
    }
    if (category == 'produce' && (listing['crop'] as String?)?.trim().isEmpty != false) {
      throw const OfflineSyncException('Crop is required for produce listings.');
    }
    if (category == 'input' && (listing['input_type'] as String?)?.trim().isEmpty != false) {
      throw const OfflineSyncException('Input type is required for input listings.');
    }
  }
}
