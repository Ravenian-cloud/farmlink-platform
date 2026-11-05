import 'package:flutter/material.dart';
import 'package:farmlink_mobile/utils/localization.dart';
import 'package:farmlink_mobile/utils/offlineSync.dart';

class ListingFormScreen extends StatefulWidget {
  const ListingFormScreen({required this.sync, super.key});

  final OfflineSync sync;

  @override
  State<ListingFormScreen> createState() => _ListingFormScreenState();
}

class _ListingFormScreenState extends State<ListingFormScreen> {
  final _formKey = GlobalKey<FormState>();
  final _name = TextEditingController();
  final _description = TextEditingController();
  final _crop = TextEditingController();
  final _inputType = TextEditingController();
  final _brand = TextEditingController();
  final _quantity = TextEditingController();
  final _unit = TextEditingController();
  final _price = TextEditingController();
  final _county = TextEditingController();
  String _category = 'produce';
  bool _saving = false;

  @override
  void dispose() {
    _name.dispose();
    _description.dispose();
    _crop.dispose();
    _inputType.dispose();
    _brand.dispose();
    _quantity.dispose();
    _unit.dispose();
    _price.dispose();
    _county.dispose();
    super.dispose();
  }

  String? _required(String? value) => (value == null || value.trim().isEmpty)
      ? AppLocalizations.of(context).listingRequired
      : null;

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    final strings = AppLocalizations.of(context);
    final price = double.tryParse(_price.text.trim());
    if (price == null ||
        !price.isFinite ||
        price <= 0 ||
        (price * 100 - (price * 100).round()).abs() > 0.00001) {
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(strings.invalidPrice)));
      return;
    }

    final payload = <String, dynamic>{
      'category': _category,
      'name': _name.text.trim(),
      'description': _description.text.trim(),
      if (_category == 'produce') 'crop': _crop.text.trim(),
      if (_category == 'input') 'input_type': _inputType.text.trim(),
      if (_brand.text.trim().isNotEmpty) 'brand': _brand.text.trim(),
      'quantity_available': double.parse(_quantity.text.trim()),
      'unit': _unit.text.trim(),
      'price_minor': (price * 100).round(),
      'currency': 'KES',
      if (_county.text.trim().isNotEmpty) 'county': _county.text.trim(),
    };

    setState(() => _saving = true);
    try {
      await widget.sync.createListingDraft(payload);
      if (!mounted) return;
      Navigator.of(context).pop(true);
    } catch (error) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(error.toString())));
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final strings = AppLocalizations.of(context);
    return Scaffold(
      appBar: AppBar(title: Text(strings.createListing)),
      body: Form(
        key: _formKey,
        child: ListView(
          padding: const EdgeInsets.all(16),
          children: [
            DropdownButtonFormField<String>(
              value: _category,
              decoration: InputDecoration(labelText: strings.category),
              items: [
                DropdownMenuItem(value: 'produce', child: Text(strings.produce)),
                DropdownMenuItem(value: 'input', child: Text(strings.farmInput)),
              ],
              onChanged: _saving ? null : (value) => setState(() => _category = value ?? 'produce'),
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _name,
              decoration: InputDecoration(labelText: strings.name),
              validator: _required,
              textCapitalization: TextCapitalization.sentences,
              maxLength: 180,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _description,
              decoration: InputDecoration(labelText: strings.description),
              maxLines: 3,
              maxLength: 10000,
              textCapitalization: TextCapitalization.sentences,
            ),
            const SizedBox(height: 12),
            if (_category == 'produce')
              TextFormField(
                controller: _crop,
                decoration: InputDecoration(labelText: strings.crop),
                validator: _required,
                maxLength: 100,
              )
            else ...[
              TextFormField(
                controller: _inputType,
                decoration: InputDecoration(labelText: strings.inputType),
                validator: _required,
                maxLength: 100,
              ),
              const SizedBox(height: 12),
              TextFormField(
                controller: _brand,
                decoration: InputDecoration(labelText: strings.brand),
                maxLength: 120,
              ),
            ],
            const SizedBox(height: 12),
            TextFormField(
              controller: _quantity,
              decoration: InputDecoration(labelText: strings.quantity),
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              validator: (value) {
                final raw = value?.trim() ?? '';
                final number = double.tryParse(raw);
                final decimals = raw.contains('.') ? raw.split('.').last.length : 0;
                return number == null ||
                        !number.isFinite ||
                        number <= 0 ||
                        number > 999999999 ||
                        decimals > 3
                    ? strings.listingRequired
                    : null;
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _unit,
              decoration: InputDecoration(labelText: strings.unit),
              validator: _required,
              maxLength: 24,
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _price,
              decoration: InputDecoration(labelText: strings.price, prefixText: 'KES '),
              keyboardType: const TextInputType.numberWithOptions(decimal: true),
              validator: (value) {
                final number = double.tryParse(value?.trim() ?? '');
                return number == null || !number.isFinite || number <= 0 ? strings.invalidPrice : null;
              },
            ),
            const SizedBox(height: 12),
            TextFormField(
              controller: _county,
              decoration: InputDecoration(labelText: strings.county),
              maxLength: 100,
            ),
            const SizedBox(height: 20),
            FilledButton.icon(
              onPressed: _saving ? null : _save,
              icon: _saving
                  ? const SizedBox.square(dimension: 18, child: CircularProgressIndicator(strokeWidth: 2))
                  : const Icon(Icons.save_outlined),
              label: Text(strings.saveListing),
            ),
            const SizedBox(height: 8),
            Text(
              _category == 'produce' ? strings.farmerRoleNote : strings.supplierRoleNote,
              textAlign: TextAlign.center,
            ),
          ],
        ),
      ),
    );
  }
}
