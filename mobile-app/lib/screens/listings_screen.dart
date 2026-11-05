import 'package:flutter/material.dart';
import 'package:farmlink_mobile/screens/listing_form_screen.dart';
import 'package:farmlink_mobile/utils/localization.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';

class ListingsScreen extends StatefulWidget {
  const ListingsScreen({
    required this.sync,
    required this.locale,
    required this.onLocaleChanged,
    required this.accessToken,
    required this.onAccessTokenChanged,
    super.key,
  });

  final OfflineSync sync;
  final Locale locale;
  final ValueChanged<Locale> onLocaleChanged;
  final String accessToken;
  final ValueChanged<String> onAccessTokenChanged;

  @override
  State<ListingsScreen> createState() => _ListingsScreenState();
}

class _ListingsScreenState extends State<ListingsScreen> {
  late Future<List<OfflineOperation>> _operations;
  late final TextEditingController _tokenController;
  List<Map<String, dynamic>> _serverListings = const [];
  bool _syncing = false;

  @override
  void initState() {
    super.initState();
    _tokenController = TextEditingController(text: widget.accessToken);
    _operations = widget.sync.getOperations();
  }

  @override
  void didUpdateWidget(covariant ListingsScreen oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.accessToken != widget.accessToken && _tokenController.text != widget.accessToken) {
      _tokenController.value = TextEditingValue(
        text: widget.accessToken,
        selection: TextSelection.collapsed(offset: widget.accessToken.length),
      );
    }
  }

  @override
  void dispose() {
    _tokenController.dispose();
    super.dispose();
  }

  void _reload() {
    if (mounted) setState(() => _operations = widget.sync.getOperations());
  }

  Future<void> _sync() async {
    setState(() => _syncing = true);
    try {
      final report = await widget.sync.syncPending();
      final serverListings = await widget.sync.getMyListings();
      if (mounted) setState(() => _serverListings = serverListings);
      _reload();
      if (mounted) {
        final strings = AppLocalizations.of(context);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${strings.synced}: ${report.synced}')),
        );
      }
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    } finally {
      if (mounted) setState(() => _syncing = false);
    }
  }

  Future<void> _openForm() async {
    final saved = await Navigator.of(context).push<bool>(
      MaterialPageRoute(builder: (_) => ListingFormScreen(sync: widget.sync)),
    );
    if (saved == true) {
      _reload();
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text(AppLocalizations.of(context).savedListing)),
        );
      }
    }
  }

  Future<void> _retry(OfflineOperation operation) async {
    try {
      await widget.sync.retryOperation(operation.id);
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    }
    _reload();
  }

  String _stateLabel(AppLocalizations strings, String state) {
    switch (state) {
      case 'synced':
        return strings.synced;
      case 'needs_attention':
        return strings.needsAttention;
      case 'pending':
        return strings.waitingToSync;
      case 'draft':
        return strings.localDraft;
      default:
        return state;
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(
        title: Text(strings.listingsTitle),
        actions: [
          FarmLinkLanguageSelector(locale: widget.locale, onChanged: widget.onLocaleChanged),
          const SizedBox(width: 8),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _openForm,
        icon: const Icon(Icons.add),
        label: Text(strings.createListing),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
            child: TextField(
              controller: _tokenController,
              obscureText: true,
              autocorrect: false,
              onChanged: widget.onAccessTokenChanged,
              onSubmitted: (_) => _loadServerListings(),
              decoration: InputDecoration(
                labelText: strings.apiToken,
                hintText: strings.tokenHint,
                helperText: strings.tokenNotSaved,
              ),
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              children: [
                Expanded(child: Text(strings.onlineSubmissionNote)),
                TextButton.icon(
                  onPressed: _syncing ? null : _sync,
                  icon: _syncing
                      ? const SizedBox.square(dimension: 16, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Icon(Icons.sync),
                  label: Text(strings.syncNow),
                ),
              ],
            ),
          ),
          Expanded(
            child: FutureBuilder<List<OfflineOperation>>(
              future: _operations,
              builder: (context, snapshot) {
                if (snapshot.connectionState == ConnectionState.waiting && !snapshot.hasData) {
                  return const Center(child: CircularProgressIndicator());
                }
                if (snapshot.hasError) return Center(child: Text(snapshot.error.toString()));
                final operations = snapshot.data ?? const <OfflineOperation>[];
                return RefreshIndicator(
                  onRefresh: () async {
                    await widget.sync.syncPending();
                    await _loadServerListings();
                    _reload();
                  },
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(16, 8, 16, 96),
                    children: [
                      if (_serverListings.isNotEmpty) ...[
                        Text(strings.onlineListings, style: Theme.of(context).textTheme.titleMedium),
                        for (final listing in _serverListings)
                          Card(
                            child: ListTile(
                              leading: const Icon(Icons.cloud_done_outlined),
                              title: Text(listing['name'] as String? ?? strings.listingsTitle),
                              subtitle: Text(
                                '${listing['quantity_available'] ?? ''} ${listing['unit'] ?? ''} · '
                                'KES ${_formatPrice(listing['price_minor'])}',
                              ),
                              trailing: listing['id'] is String
                                  ? Tooltip(
                                      message: strings.serverId,
                                      child: const Icon(Icons.verified_outlined),
                                    )
                                  : null,
                            ),
                          ),
                      ],
                      for (final operation in operations.where((item) =>
                          item.operation == 'create_product' &&
                          !_serverListings.any((listing) => listing['id'] == item.serverEntityId)))
                        _operationCard(strings, operation),
                      if (_serverListings.isEmpty &&
                          operations.every((item) => item.operation != 'create_product'))
                        Center(
                          child: Padding(
                          padding: const EdgeInsets.all(24),
                          child: Text(strings.noListings),
                          ),
                        ),
                    ],
                  ),
                );
              },
            ),
          ),
        ],
      ),
    );
  }

  Future<void> _loadServerListings() async {
    try {
      final listings = await widget.sync.getMyListings();
      if (mounted) setState(() => _serverListings = listings);
    } catch (error) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
      }
    }
  }

  String _formatPrice(dynamic minorUnits) {
    final value = int.tryParse(minorUnits?.toString() ?? '');
    return value == null ? '—' : (value / 100).toStringAsFixed(2);
  }

  Widget _operationCard(AppLocalizations strings, OfflineOperation operation) {
    final category = operation.payload['category'] == 'produce' ? strings.produce : strings.farmInput;
    return Card(
      child: ListTile(
        title: Text(operation.payload['name'] as String? ?? category),
        subtitle: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text('$category · ${operation.payload['quantity_available']} ${operation.payload['unit'] ?? ''}'),
            Text('${strings.status}: ${_stateLabel(strings, operation.state)}'),
            if (operation.serverEntityId != null)
              Text('${strings.serverId}: ${operation.serverEntityId}'),
            if (operation.lastError != null)
              Text(operation.lastError!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
          ],
        ),
        isThreeLine: operation.lastError != null || operation.serverEntityId != null,
        trailing: operation.state == 'synced'
            ? const Icon(Icons.cloud_done_outlined)
            : IconButton(
                tooltip: strings.retry,
                onPressed: () => _retry(operation),
                icon: const Icon(Icons.refresh),
              ),
      ),
    );
  }
}
