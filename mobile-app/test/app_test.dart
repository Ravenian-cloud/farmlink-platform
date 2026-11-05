import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:farmlink_mobile/app.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

void main() {
  TestWidgetsFlutterBinding.ensureInitialized();
  sqfliteFfiInit();

  OfflineSync createSync() => OfflineSync(
        apiBaseUrl: 'https://api.example.test/api/v1',
        accountId: 'widget-test',
        accessTokenProvider: () async => null,
        localDatabaseFactory: databaseFactoryFfi,
        localDatabasePath: inMemoryDatabasePath,
        connectivityCheck: () async => false,
      );

  testWidgets('launches listing shell and persists the selected language', (tester) async {
    final sync = createSync();
    await tester.pumpWidget(FarmLinkApp(sync: sync));
    await tester.pumpAndSettle();

    expect(find.text('My listings'), findsOneWidget);
    expect(find.text('No listings yet.'), findsOneWidget);

    await tester.tap(find.byType(DropdownButton<Locale>));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Kiswahili').last);
    await tester.pumpAndSettle();

    expect(find.text('Matangazo yangu'), findsOneWidget);
    expect(await sync.getPreference('language'), 'sw');
    await tester.pumpWidget(const SizedBox.shrink());
    await sync.dispose();
  });

  testWidgets('saves a produce listing as a usable offline draft', (tester) async {
    final sync = createSync();
    await tester.pumpWidget(FarmLinkApp(sync: sync));
    await tester.pumpAndSettle();
    await tester.tap(find.text('Create listing'));
    await tester.pumpAndSettle();

    final fields = find.byType(TextFormField);
    await tester.enterText(fields.at(0), 'Maize');
    await tester.enterText(fields.at(2), 'Maize');
    await tester.enterText(fields.at(3), '50');
    await tester.enterText(fields.at(4), 'kg');
    await tester.enterText(fields.at(5), '75.50');
    await tester.tap(find.text('Save listing'));
    await tester.pumpAndSettle();

    expect(find.text('Maize'), findsOneWidget);
    final operation = (await sync.getOperations()).single;
    expect(operation.state, 'pending');
    expect(operation.payload['price_minor'], 7550);
    expect(operation.payload['currency'], 'KES');
    await tester.pumpWidget(const SizedBox.shrink());
    await sync.dispose();
  });
}
