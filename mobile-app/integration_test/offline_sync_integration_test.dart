import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:integration_test/integration_test.dart';
import 'package:path/path.dart' as path;
import 'package:sqflite/sqflite.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';

void main() {
  IntegrationTestWidgetsFlutterBinding.ensureInitialized();

  testWidgets('offline listing syncs after reconnect and bid acceptance stays online-only', (tester) async {
    var online = false;
    final requests = <http.Request>[];
    final client = MockClient((request) async {
      requests.add(request);
      if (request.url.path.endsWith('/products')) {
        return http.Response('{"data":{"id":"server-product"}}', 201);
      }
      if (request.url.path.endsWith('/bids/pilot-bid/accept')) {
        return http.Response('{"data":{"bid":{"status":"accepted"}}}', 200);
      }
      return http.Response('{}', 404);
    });
    final databasePath = path.join(
      await getDatabasesPath(),
      'farmlink_offline_integration_${DateTime.now().microsecondsSinceEpoch}.db',
    );

    final sync = OfflineSync(
      apiBaseUrl: 'https://api.example.test/api/v1',
      accountId: 'pilot-farmer',
      accessTokenProvider: () async => 'integration-test-token',
      httpClient: client,
      localDatabasePath: databasePath,
      connectivityCheck: () async => online,
    );

    try {
      final listingId = await sync.createListingDraft({
        'category': 'produce',
        'name': 'Pilot maize',
        'crop': 'maize',
        'quantity_available': 25,
        'unit': 'kg',
        'price_minor': 3000,
        'currency': 'KES',
      });
      expect(listingId, isNotEmpty);
      expect(requests, isEmpty);

      await expectLater(
        sync.acceptBid(
          'pilot-bid',
          idempotencyKey: '650b2ac1-3a0c-4c00-8f66-886cf58b9340',
        ),
        throwsA(isA<OfflineSyncException>()),
      );
      expect(requests, isEmpty);

      online = true;
      final report = await sync.syncPending();
      expect(report.synced, 1);
      expect(requests.single.url.path, endsWith('/products'));

      final accepted = await sync.acceptBid(
        'pilot-bid',
        idempotencyKey: '650b2ac1-3a0c-4c00-8f66-886cf58b9340',
      );
      expect(accepted['data']['bid']['status'], 'accepted');
      expect(requests.last.url.path, endsWith('/bids/pilot-bid/accept'));
    } finally {
      await sync.dispose();
    }
  });
}
