import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();

  group('OfflineSync', () {
    late bool online;
    late List<http.Request> requests;
    late OfflineSync sync;

    setUp(() {
      online = false;
      requests = [];
      final client = MockClient((request) async {
        requests.add(request);
        if (request.method == 'GET' && request.url.path.endsWith('/my/products')) {
          return http.Response(
            jsonEncode({
              'data': [
                {'id': 'server-product-id', 'name': 'Maize', 'price_minor': 5000}
              ]
            }),
            200,
          );
        }
        if (request.method == 'POST' && request.url.path.endsWith('/products')) {
          return http.Response(
            jsonEncode({'data': {'id': 'server-product-id'}}),
            201,
            headers: {'content-type': 'application/json'},
          );
        }
        if (request.method == 'POST' && request.url.path.endsWith('/bids/bid-1/accept')) {
          return http.Response(
            jsonEncode({'data': {'bid': {'status': 'accepted'}}}),
            200,
            headers: {'content-type': 'application/json'},
          );
        }
        return http.Response('{}', 404);
      });

      sync = OfflineSync(
        apiBaseUrl: 'https://api.example.test/api/v1',
        accountId: 'pilot-user-1',
        accessTokenProvider: () async => 'test-token',
        httpClient: client,
        localDatabaseFactory: databaseFactoryFfi,
        localDatabasePath: inMemoryDatabasePath,
        connectivityCheck: () async => online,
        requestTimeout: const Duration(milliseconds: 25),
      );
    });

    tearDown(() async {
      await sync.dispose();
    });

    test('queues a produce listing offline and syncs it after reconnection', () async {
      final operationId = await sync.createListingDraft({
        'category': 'produce',
        'name': 'Maize',
        'crop': 'maize',
        'quantity_available': 100,
        'unit': 'kg',
        'price_minor': 5000,
        'currency': 'KES',
      });

      expect(requests, isEmpty);
      expect((await sync.getOperations()).single.id, operationId);

      online = true;
      final report = await sync.syncPending();

      expect(report.synced, 1);
      expect(requests, hasLength(1));
      expect(requests.single.url.path, endsWith('/products'));
      final payload = jsonDecode(requests.single.body) as Map<String, dynamic>;
      expect(payload['category'], 'produce');
      expect(payload['price_minor'], 5000);
      expect(payload['currency'], 'KES');
      expect(requests.single.headers['authorization'], 'Bearer test-token');
      expect(requests.single.headers['idempotency-key'], isNotEmpty);
      final operation = (await sync.getOperations()).single;
      expect(operation.state, 'synced');
      expect(operation.serverEntityId, 'server-product-id');
    });

    test('does not accept a bid offline, but allows server acceptance when online', () async {
      await expectLater(
        sync.acceptBid('bid-1', idempotencyKey: '650b2ac1-3a0c-4c00-8f66-886cf58b9340'),
        throwsA(isA<OfflineSyncException>().having(
          (error) => error.message,
          'message',
          contains('requires an active connection'),
        )),
      );
      expect(requests, isEmpty);

      online = true;
      final result = await sync.acceptBid(
        'bid-1',
        idempotencyKey: '650b2ac1-3a0c-4c00-8f66-886cf58b9340',
      );

      expect(result['data']['bid']['status'], 'accepted');
      expect(requests.single.url.path, endsWith('/bids/bid-1/accept'));
      expect(requests.single.headers['idempotency-key'], isNotEmpty);
    });

    test('a timed-out listing remains queued with a retryable error', () async {
      final slowClient = MockClient((request) async {
        await Future<void>.delayed(const Duration(milliseconds: 100));
        return http.Response(jsonEncode({'data': {'id': 'created-later'}}), 201);
      });
      await sync.dispose();
      sync = OfflineSync(
        apiBaseUrl: 'https://api.example.test/api/v1',
        accountId: 'pilot-user-timeout',
        accessTokenProvider: () async => 'test-token',
        httpClient: slowClient,
        localDatabaseFactory: databaseFactoryFfi,
        localDatabasePath: inMemoryDatabasePath,
        connectivityCheck: () async => true,
        requestTimeout: const Duration(milliseconds: 10),
      );

      final operationId = await sync.createListingDraft({
        'category': 'produce',
        'name': 'Maize',
        'crop': 'maize',
        'quantity_available': 100,
        'unit': 'kg',
        'price_minor': 5000,
        'currency': 'KES',
      });
      final operation = (await sync.getOperations()).singleWhere((item) => item.id == operationId);

      expect(operation.state, 'pending');
      expect(operation.attemptCount, 1);
      expect(operation.lastError, contains('timed out'));
    });

    test('language preference is retained by the local database', () async {
      await sync.setPreference('language', 'sw');
      expect(await sync.getPreference('language'), 'sw');
    });

    test('loads authenticated seller listings from the backend', () async {
      online = true;
      final listings = await sync.getMyListings();

      expect(listings.single['id'], 'server-product-id');
      expect(requests.single.method, 'GET');
      expect(requests.single.url.path, endsWith('/my/products'));
    });
  });
}
