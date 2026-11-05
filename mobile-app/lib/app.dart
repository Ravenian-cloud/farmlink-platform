import 'dart:async';

import 'package:flutter/material.dart';
import 'package:farmlink_mobile/screens/listings_screen.dart';
import 'package:farmlink_mobile/utils/localization.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';

class FarmLinkApp extends StatefulWidget {
  const FarmLinkApp({
    this.sync,
    this.apiBaseUrl = const String.fromEnvironment(
      'FARMLINK_API_BASE_URL',
      defaultValue: 'http://10.0.2.2:8000/api/v1',
    ),
    super.key,
  });

  final OfflineSync? sync;
  final String apiBaseUrl;

  @override
  State<FarmLinkApp> createState() => _FarmLinkAppState();
}

class _FarmLinkAppState extends State<FarmLinkApp> {
  OfflineSync? _sync;
  bool _ownsSync = false;
  Locale _locale = const Locale('en');
  String _accessToken = '';
  String? _startupError;

  @override
  void initState() {
    super.initState();
    unawaited(_initialize());
  }

  Future<void> _initialize() async {
    try {
      final sync = widget.sync ??
          OfflineSync(
            apiBaseUrl: widget.apiBaseUrl,
            accountId: const String.fromEnvironment(
              'FARMLINK_LOCAL_ACCOUNT_ID',
              defaultValue: 'local-device',
            ),
            accessTokenProvider: () async => _accessToken.trim().isEmpty ? null : _accessToken.trim(),
          );
      _ownsSync = widget.sync == null;
      await sync.initialize();
      final savedLanguage = await sync.getPreference('language');
      if (!mounted) {
        if (widget.sync == null) await sync.dispose();
        return;
      }
      setState(() {
        _sync = sync;
        if (savedLanguage == 'sw') _locale = const Locale('sw');
      });
    } catch (error) {
      if (!mounted) return;
      setState(() => _startupError = error.toString());
    }
  }

  Future<void> _changeLanguage(Locale locale) async {
    setState(() => _locale = locale);
    await _sync?.setPreference('language', locale.languageCode);
  }

  @override
  void dispose() {
    if (_ownsSync) unawaited(_sync?.dispose() ?? Future<void>.value());
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: 'FarmLink',
      debugShowCheckedModeBanner: false,
      locale: _locale,
      supportedLocales: AppLocalizations.supportedLocales,
      localizationsDelegates: farmLinkLocalizationDelegates,
      theme: ThemeData(
        colorScheme: ColorScheme.fromSeed(seedColor: const Color(0xFF287A42)),
        useMaterial3: true,
        inputDecorationTheme: const InputDecorationTheme(border: OutlineInputBorder()),
      ),
      home: _sync != null
          ? ListingsScreen(
              sync: _sync!,
              locale: _locale,
              onLocaleChanged: _changeLanguage,
              accessToken: _accessToken,
              onAccessTokenChanged: (value) => setState(() => _accessToken = value),
            )
          : Scaffold(
              body: Center(
                child: _startupError == null
                    ? const CircularProgressIndicator()
                    : Padding(
                        padding: const EdgeInsets.all(24),
                        child: Text(_startupError!, textAlign: TextAlign.center),
                      ),
              ),
            ),
    );
  }
}
