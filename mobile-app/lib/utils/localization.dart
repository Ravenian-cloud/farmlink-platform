import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';

class AppLocalizations {
  const AppLocalizations(this.locale);

  final Locale locale;

  static const supportedLocales = [Locale('en'), Locale('sw')];

  static AppLocalizations of(BuildContext context) {
    final result = Localizations.of<AppLocalizations>(context, AppLocalizations);
    assert(result != null, 'AppLocalizations was not provided by the widget tree.');
    return result!;
  }

  bool get _swahili => locale.languageCode == 'sw';
  String get languageName => _swahili ? 'Kiswahili' : 'English';
  String get languageLabel => _swahili ? 'Lugha' : 'Language';
  String get appTitle => 'FarmLink';
  String get listingsTitle => _swahili ? 'Matangazo yangu' : 'My listings';
  String get createListing => _swahili ? 'Ongeza tangazo' : 'Create listing';
  String get listingDrafts => _swahili ? 'Matangazo na rasimu' : 'Listings and drafts';
  String get onlineListings => _swahili ? 'Matangazo ya seva' : 'Online listings';
  String get syncNow => _swahili ? 'Sawazisha sasa' : 'Sync now';
  String get apiToken => _swahili ? 'Tokeni ya API' : 'API access token';
  String get tokenHint => _swahili ? 'Bandika tokeni yako ya kuingia' : 'Paste your existing bearer token';
  String get tokenNotSaved => _swahili ? 'Tokeni haijahifadhiwa kwenye kifaa.' : 'Token is kept in memory only.';
  String get category => _swahili ? 'Aina ya tangazo' : 'Listing category';
  String get produce => _swahili ? 'Mazao' : 'Produce';
  String get farmInput => _swahili ? 'Pembejeo' : 'Farm input';
  String get name => _swahili ? 'Jina' : 'Name';
  String get description => _swahili ? 'Maelezo' : 'Description';
  String get crop => _swahili ? 'Zao' : 'Crop';
  String get inputType => _swahili ? 'Aina ya pembejeo' : 'Input type';
  String get brand => _swahili ? 'Chapa' : 'Brand';
  String get quantity => _swahili ? 'Kiasi kinachopatikana' : 'Available quantity';
  String get unit => _swahili ? 'Kipimo (mf. kg)' : 'Unit (e.g. kg)';
  String get price => _swahili ? 'Bei kwa KES' : 'Price in KES';
  String get county => _swahili ? 'Kaunti' : 'County';
  String get saveListing => _swahili ? 'Hifadhi tangazo' : 'Save listing';
  String get cancel => _swahili ? 'Ghairi' : 'Cancel';
  String get retry => _swahili ? 'Jaribu tena' : 'Retry';
  String get localDraft => _swahili ? 'Rasimu ya nje ya mtandao' : 'Offline draft';
  String get waitingToSync => _swahili ? 'Inasubiri kusawazishwa' : 'Waiting to sync';
  String get synced => _swahili ? 'Imesawazishwa' : 'Synced';
  String get needsAttention => _swahili ? 'Inahitaji hatua' : 'Needs attention';
  String get noListings => _swahili ? 'Bado huna matangazo.' : 'No listings yet.';
  String get savedOffline => _swahili ? 'Rasimu imehifadhiwa kwenye kifaa.' : 'Listing saved on this device.';
  String get savedListing => _swahili ? 'Tangazo limehifadhiwa.' : 'Listing saved.';
  String get listingRequired => _swahili ? 'Jaza sehemu zinazohitajika.' : 'Complete the required fields.';
  String get invalidPrice => _swahili ? 'Weka bei halali ya KES.' : 'Enter a valid KES price.';
  String get onlineSubmissionNote => _swahili
      ? 'Kuchapisha kunahitaji tokeni halali na akaunti iliyothibitishwa.'
      : 'Publishing requires a valid token and a verified account.';
  String get status => _swahili ? 'Hali' : 'Status';
  String get serverId => _swahili ? 'Kitambulisho cha seva' : 'Server ID';
  String get connectionRequired => _swahili ? 'Muunganisho unahitajika' : 'Connection required';
  String get farmerRoleNote => _swahili
      ? 'Mazao yanahitaji akaunti ya mkulima.'
      : 'Produce listings require a farmer account.';
  String get supplierRoleNote => _swahili
      ? 'Pembejeo zinahitaji akaunti ya msambazaji.'
      : 'Input listings require a supplier account.';
  String get englishName => 'English';
  String get swahiliName => 'Kiswahili';
}

class AppLocalizationsDelegate extends LocalizationsDelegate<AppLocalizations> {
  const AppLocalizationsDelegate();

  @override
  bool isSupported(Locale locale) => const ['en', 'sw'].contains(locale.languageCode);

  @override
  Future<AppLocalizations> load(Locale locale) =>
      SynchronousFuture(AppLocalizations(locale));

  @override
  bool shouldReload(AppLocalizationsDelegate old) => false;
}

class FarmLinkLanguageSelector extends StatelessWidget {
  const FarmLinkLanguageSelector({
    required this.locale,
    required this.onChanged,
    super.key,
  });

  final Locale locale;
  final ValueChanged<Locale> onChanged;

  @override
  Widget build(BuildContext context) {
    final strings = AppLocalizations.of(context);
    return Semantics(
      label: strings.languageLabel,
      child: DropdownButton<Locale>(
        value: locale,
        onChanged: (next) {
          if (next != null) onChanged(next);
        },
        items: [
          DropdownMenuItem(value: const Locale('en'), child: Text(strings.englishName)),
          DropdownMenuItem(value: const Locale('sw'), child: Text(strings.swahiliName)),
        ],
      ),
    );
  }
}

const farmLinkLocalizationDelegates = <LocalizationsDelegate<dynamic>>[
  AppLocalizationsDelegate(),
  GlobalMaterialLocalizations.delegate,
  GlobalWidgetsLocalizations.delegate,
  GlobalCupertinoLocalizations.delegate,
];
