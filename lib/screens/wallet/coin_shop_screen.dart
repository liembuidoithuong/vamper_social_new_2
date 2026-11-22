import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import '../../app/theme/app_colors.dart';
import '../../controllers/auth_controller.dart';
import '../../core/constants/api_constants.dart';
import '../../widgets/vpc_coin_icon.dart';
import 'store_screen.dart';

class CoinShopScreen extends StatefulWidget {
  const CoinShopScreen({super.key});

  @override
  State<CoinShopScreen> createState() => _CoinShopScreenState();
}

class _CoinShopScreenState extends State<CoinShopScreen> {
  String? _paymentSettingsError;
  String? _marketPricesError;
  final List<Map<String, dynamic>> _paymentMethods = [];
  final Map<String, Map<String, String>> _marketPrices = {};
  String? _selectedPaymentMethod;
  bool _isLoadingPaymentSettings = true;
  bool _isRefreshingMarketPrices = false;
  bool _isCreatingOrder = false;

  bool get _canPurchase =>
      !_isLoadingPaymentSettings &&
      _paymentSettingsError == null &&
      _paymentMethods.isNotEmpty &&
      _selectedPaymentMethod != null;

  Map<String, dynamic>? get _selectedMethod {
    for (final method in _paymentMethods) {
      if (method['id'] == _selectedPaymentMethod) return method;
    }
    return null;
  }

  bool get _isSelectedTestnet =>
      (_selectedMethod?['network']?.toString() ?? '').contains('testnet');

  @override
  void initState() {
    super.initState();
    _loadPaymentSettings();
    _loadMarketPrices();
  }

  Future<void> _loadMarketPrices() async {
    if (mounted) {
      setState(() {
        _isRefreshingMarketPrices = true;
        _marketPricesError = null;
      });
    }

    try {
      final response = await Get.find<AuthController>().apiService.get(
        ApiConstants.getCryptoMarketPrices,
      );
      if (response.data['success'] != true) {
        throw StateError(
          response.data['message']?.toString() ??
              'Unable to load current market prices.',
        );
      }

      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      final rawPrices = Map<String, dynamic>.from(data['prices'] as Map);
      final prices = <String, Map<String, String>>{};
      for (final entry in rawPrices.entries) {
        if (entry.value is! Map) {
          throw StateError('The market price response is invalid.');
        }
        final value = Map<String, dynamic>.from(entry.value as Map);
        final price = value['price_usd']?.toString();
        final updatedAt = value['updated_at']?.toString();
        if (price == null ||
            double.tryParse(price) == null ||
            updatedAt == null ||
            DateTime.tryParse(updatedAt) == null) {
          throw StateError('The market price response is invalid.');
        }
        prices[entry.key] = {'price_usd': price, 'updated_at': updatedAt};
      }
      if (prices.isEmpty) {
        throw StateError('No current market prices were returned.');
      }

      if (!mounted) return;
      setState(() {
        _marketPrices
          ..clear()
          ..addAll(prices);
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _marketPricesError = error.toString().replaceFirst('Bad state: ', '');
      });
    } finally {
      if (mounted) {
        setState(() => _isRefreshingMarketPrices = false);
      }
    }
  }

  Future<void> _loadPaymentSettings() async {
    if (mounted) {
      setState(() {
        _isLoadingPaymentSettings = true;
        _paymentSettingsError = null;
      });
    }

    try {
      final response = await Get.find<AuthController>().apiService.get(
        ApiConstants.getCryptoPaymentSettings,
      );
      if (response.data['success'] != true) {
        throw StateError(
          response.data['message']?.toString() ??
              'Unable to load crypto payment settings.',
        );
      }

      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      final checkoutEnabled = data['checkout_enabled'] == true;
      final network = data['network']?.toString();
      final tokenContract = data['token_contract']?.toString().toLowerCase();
      final chainId = int.tryParse(data['chain_id']?.toString() ?? '');
      final isMainnet =
          chainId == 137 &&
          network == 'Polygon PoS' &&
          tokenContract == '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';
      final isAmoyTestnet =
          chainId == 80002 &&
          network == 'Polygon PoS Amoy (testnet)' &&
          tokenContract == '0x41e94eb019c0762f9bfcf9fb1e58725bfb0e7582';
      if (checkoutEnabled && !(isMainnet || isAmoyTestnet)) {
        throw StateError(
          'The server returned an unsupported Polygon USDC network.',
        );
      }
      final methods = <Map<String, dynamic>>[];
      final rawMethods = data['payment_methods'];
      if (rawMethods == null) {
        if (checkoutEnabled && (isMainnet || isAmoyTestnet)) {
          methods.add({
            'id': 'polygon_usdc',
            'label': isAmoyTestnet
                ? 'USDC - Polygon Amoy (testnet)'
                : 'USDC - Polygon PoS',
            'network': network,
            'asset': 'USDC',
          });
        }
      } else if (rawMethods is! List) {
        throw StateError('The server returned invalid crypto payment methods.');
      } else {
        for (final value in rawMethods) {
          if (value is! Map) {
            throw StateError(
              'The server returned an invalid crypto payment method.',
            );
          }
          final method = Map<String, dynamic>.from(value);
          final id = method['id']?.toString();
          final methodNetwork = method['network']?.toString();
          final asset = method['asset']?.toString();
          final isValidPolygon =
              id == 'polygon_usdc' &&
              asset == 'USDC' &&
              ((methodNetwork == 'Polygon PoS' && isMainnet) ||
                  (methodNetwork == 'Polygon PoS Amoy (testnet)' &&
                      isAmoyTestnet));
          final isValidSolana =
              (id == 'solana_sol' && asset == 'SOL') ||
              (id == 'solana_usdc' && asset == 'USDC') ||
              (id == 'solana_usdt' && asset == 'USDT');
          final validSolanaNetwork =
              methodNetwork == 'Solana Mainnet' ||
              methodNetwork == 'Solana Devnet (testnet)';
          if (!isValidPolygon && !(isValidSolana && validSolanaNetwork)) {
            throw StateError(
              'The server returned an unsupported crypto payment method.',
            );
          }
          methods.add(method);
        }
      }

      if (!mounted) return;
      setState(() {
        _paymentMethods
          ..clear()
          ..addAll(methods);
        _selectedPaymentMethod ??= methods.isEmpty
            ? null
            : methods.first['id']?.toString();
        if (!methods.any((method) => method['id'] == _selectedPaymentMethod)) {
          _selectedPaymentMethod = methods.isEmpty
              ? null
              : methods.first['id']?.toString();
        }
        _isLoadingPaymentSettings = false;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        _paymentMethods.clear();
        _selectedPaymentMethod = null;
        _paymentSettingsError = error.toString().replaceFirst(
          'Bad state: ',
          '',
        );
        _isLoadingPaymentSettings = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    final AuthController authController = Get.find<AuthController>();
    final activeAssets = _paymentMethods
        .map((method) => method['asset']?.toString())
        .whereType<String>()
        .toSet();
    final displayedMarketPrices = Map<String, Map<String, String>>.fromEntries(
      _marketPrices.entries.where((entry) => activeAssets.contains(entry.key)),
    );
    final latestMarketPriceUpdate = displayedMarketPrices.values
        .map((price) => DateTime.tryParse(price['updated_at'] ?? ''))
        .whereType<DateTime>()
        .fold<DateTime?>(
          null,
          (latest, updatedAt) =>
              latest == null || updatedAt.isAfter(latest) ? updatedAt : latest,
        );

    final packages = [
      {
        'coins': 100,
        'price': '4.99',
        'label': 'Starter Pack',
        'popular': false,
      },
      {
        'coins': 500,
        'price': '19.99',
        'label': 'Popular Pack',
        'popular': true,
      },
      {
        'coins': 1200,
        'price': '39.99',
        'label': 'Best Value',
        'popular': false,
      },
      {
        'coins': 3000,
        'price': '89.99',
        'label': 'VIP Master',
        'popular': false,
      },
    ];

    return Scaffold(
      backgroundColor: AppColors.background,
      appBar: AppBar(
        backgroundColor: AppColors.background,
        elevation: 0,
        title: const Text(
          'Coin Store',
          style: TextStyle(
            color: AppColors.textPrimary,
            fontWeight: FontWeight.bold,
          ),
        ),
        centerTitle: true,
        iconTheme: const IconThemeData(color: AppColors.textPrimary),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 8, 20, 28),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Container(
              padding: const EdgeInsets.all(22),
              decoration: BoxDecoration(
                gradient: AppColors.primaryGradient,
                borderRadius: BorderRadius.circular(26),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.3),
                    blurRadius: 22,
                    offset: const Offset(0, 10),
                  ),
                ],
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'YOUR VPC BALANCE',
                          style: TextStyle(
                            color: Colors.white70,
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                            letterSpacing: 1.1,
                          ),
                        ),
                        const SizedBox(height: 10),
                        Obx(
                          () => Text(
                            '${authController.currentUser.value?.coins ?? 0}',
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 34,
                              fontWeight: FontWeight.bold,
                              height: 1,
                            ),
                          ),
                        ),
                        const SizedBox(height: 6),
                        const Text(
                          'Vamper Coins',
                          style: TextStyle(
                            color: Colors.white70,
                            fontSize: 13,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(width: 14),
                  Container(
                    padding: const EdgeInsets.all(15),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      shape: BoxShape.circle,
                    ),
                    child: const VpcCoinIcon(size: 48),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: AppColors.primary,
                side: BorderSide(
                  color: AppColors.primary.withValues(alpha: 0.18),
                ),
                elevation: 0,
                padding: const EdgeInsets.symmetric(vertical: 15),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(18),
                ),
              ),
              onPressed: () => Get.to(() => const StoreScreen()),
              icon: const Icon(
                Icons.storefront_rounded,
                color: AppColors.primary,
              ),
              label: const Text(
                'Open Avatar & Frame Store 🛍️',
                style: TextStyle(fontWeight: FontWeight.bold, fontSize: 15),
              ),
            ),
            const SizedBox(height: 24),
            const Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Text(
                  'Choose your coins',
                  style: TextStyle(
                    fontSize: 21,
                    fontWeight: FontWeight.bold,
                    color: AppColors.textPrimary,
                  ),
                ),
                SizedBox(height: 4),
                Text(
                  'Pick a package that suits you',
                  style: TextStyle(
                    fontSize: 13,
                    color: AppColors.textSecondary,
                  ),
                ),
              ],
            ),
            const SizedBox(height: 16),
            if (_isLoadingPaymentSettings) ...[
              const Padding(
                padding: EdgeInsets.symmetric(vertical: 12),
                child: Center(
                  child: CircularProgressIndicator(color: AppColors.primary),
                ),
              ),
            ] else if (_paymentSettingsError != null) ...[
              Container(
                padding: const EdgeInsets.all(14),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.error.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(
                    color: AppColors.error.withValues(alpha: 0.16),
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      'Could not load payment settings: $_paymentSettingsError',
                      style: const TextStyle(
                        color: AppColors.error,
                        fontSize: 12,
                      ),
                    ),
                    TextButton(
                      onPressed: _loadPaymentSettings,
                      child: const Text('Retry'),
                    ),
                  ],
                ),
              ),
            ] else if (_canPurchase) ...[
              Container(
                padding: const EdgeInsets.all(14),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: (_isSelectedTestnet
                          ? AppColors.error
                          : AppColors.primary)
                      .withValues(alpha: 0.07),
                  borderRadius: BorderRadius.circular(16),
                  border: Border.all(
                    color: (_isSelectedTestnet
                            ? AppColors.error
                            : AppColors.primary)
                        .withValues(alpha: 0.14),
                  ),
                ),
                child: Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Icon(
                      _isSelectedTestnet
                          ? Icons.science_outlined
                          : Icons.verified_user_outlined,
                      size: 19,
                      color: _isSelectedTestnet
                          ? AppColors.error
                          : AppColors.primary,
                    ),
                    const SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        _isSelectedTestnet
                            ? 'TESTNET ONLY: Test tokens have no real value. Use the selected test network only; never send mainnet funds.'
                            : 'Send only the exact asset and amount shown at checkout, using the displayed network and recipient.',
                        style: TextStyle(
                          color: _isSelectedTestnet
                              ? AppColors.error
                              : AppColors.textSecondary,
                          fontSize: 12,
                          height: 1.4,
                          fontWeight: _isSelectedTestnet
                              ? FontWeight.w600
                              : FontWeight.normal,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ] else ...[
              Container(
                padding: const EdgeInsets.all(14),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: AppColors.accent.withValues(alpha: 0.12),
                  borderRadius: BorderRadius.circular(16),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.info_outline, color: AppColors.textSecondary),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Crypto top-ups are not available yet. Please try again later.',
                        style: TextStyle(
                          color: AppColors.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                    ),
                  ],
                ),
              ),
            ],

            if (_canPurchase) ...[
              DropdownButtonFormField<String>(
                value: _selectedPaymentMethod,
                decoration: InputDecoration(
                  labelText: 'Payment method',
                  prefixIcon: const Icon(
                    Icons.account_balance_wallet_outlined,
                    color: AppColors.primary,
                  ),
                  filled: true,
                  fillColor: Colors.white,
                  contentPadding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 16,
                  ),
                  enabledBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(16),
                    borderSide: const BorderSide(color: AppColors.border),
                  ),
                  focusedBorder: OutlineInputBorder(
                    borderRadius: BorderRadius.circular(16),
                    borderSide: const BorderSide(
                      color: AppColors.primary,
                      width: 1.5,
                    ),
                  ),
                ),
                items: _paymentMethods
                    .map(
                      (method) => DropdownMenuItem<String>(
                        value: method['id']?.toString(),
                        child: Text(method['label']?.toString() ?? ''),
                      ),
                    )
                    .toList(),
                onChanged: _isCreatingOrder
                    ? null
                    : (value) => setState(() {
                        _selectedPaymentMethod = value;
                      }),
              ),
              const SizedBox(height: 16),
              Container(
                padding: const EdgeInsets.all(16),
                decoration: BoxDecoration(
                  gradient: AppColors.softGradient,
                  borderRadius: BorderRadius.circular(20),
                  border: Border.all(
                    color: AppColors.primary.withValues(alpha: 0.1),
                  ),
                ),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Row(
                      children: [
                        const Expanded(
                          child: Row(
                            children: [
                              Icon(
                                Icons.show_chart_rounded,
                                color: AppColors.primary,
                                size: 20,
                              ),
                              SizedBox(width: 8),
                              Text(
                                'Market prices',
                                style: TextStyle(
                                  fontWeight: FontWeight.bold,
                                  color: AppColors.textPrimary,
                                  fontSize: 15,
                                ),
                              ),
                            ],
                          ),
                        ),
                        IconButton(
                          tooltip: 'Refresh market prices',
                          visualDensity: VisualDensity.compact,
                          onPressed: _isRefreshingMarketPrices
                              ? null
                              : _loadMarketPrices,
                          icon: _isRefreshingMarketPrices
                              ? const SizedBox(
                                  width: 18,
                                  height: 18,
                                  child: CircularProgressIndicator(
                                    strokeWidth: 2,
                                  ),
                                )
                              : const Icon(
                                  Icons.refresh_rounded,
                                  color: AppColors.primary,
                                ),
                        ),
                      ],
                    ),
                    if (displayedMarketPrices.isNotEmpty)
                      Wrap(
                        spacing: 10,
                        runSpacing: 10,
                        children: displayedMarketPrices.entries.map((entry) {
                          return Container(
                            padding: const EdgeInsets.symmetric(
                              horizontal: 12,
                              vertical: 10,
                            ),
                            decoration: BoxDecoration(
                              color: Colors.white.withValues(alpha: 0.88),
                              borderRadius: BorderRadius.circular(14),
                              border: Border.all(
                                color: AppColors.primary.withValues(alpha: 0.1),
                              ),
                            ),
                            child: Row(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Container(
                                  width: 30,
                                  height: 30,
                                  decoration: BoxDecoration(
                                    color: AppColors.primary.withValues(
                                      alpha: 0.1,
                                    ),
                                    shape: BoxShape.circle,
                                  ),
                                  child: Icon(
                                    entry.key == 'SOL'
                                        ? Icons.currency_exchange_rounded
                                        : Icons.attach_money_rounded,
                                    size: 17,
                                    color: AppColors.primary,
                                  ),
                                ),
                                const SizedBox(width: 9),
                                Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Text(
                                      entry.key,
                                      style: const TextStyle(
                                        color: AppColors.textSecondary,
                                        fontSize: 11,
                                        fontWeight: FontWeight.w600,
                                      ),
                                    ),
                                    Text(
                                      '\$${entry.value['price_usd']}',
                                      style: const TextStyle(
                                        color: AppColors.textPrimary,
                                        fontWeight: FontWeight.bold,
                                        fontSize: 14,
                                      ),
                                    ),
                                  ],
                                ),
                              ],
                            ),
                          );
                        }).toList(),
                      ),
                    if (_marketPricesError != null)
                      Padding(
                        padding: const EdgeInsets.only(top: 8),
                        child: Text(
                          displayedMarketPrices.isEmpty
                              ? 'Live market prices unavailable: $_marketPricesError'
                              : 'Could not refresh prices: $_marketPricesError',
                          style: const TextStyle(
                            color: AppColors.error,
                            fontSize: 12,
                          ),
                        ),
                      )
                    else if (displayedMarketPrices.isEmpty &&
                        !_isRefreshingMarketPrices)
                      const Text(
                        'Current market prices are unavailable.',
                        style: TextStyle(
                          color: AppColors.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                    if (displayedMarketPrices.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Text(
                        _isSelectedTestnet
                            ? 'Mainnet price references only. Testnet tokens have no real market value.'
                            : 'Reference prices. A SOL checkout locks its quote when the order is created.',
                        style: const TextStyle(
                          color: AppColors.textSecondary,
                          fontSize: 11,
                          height: 1.35,
                        ),
                      ),
                    ],
                    if (displayedMarketPrices.isNotEmpty)
                      Padding(
                        padding: const EdgeInsets.only(top: 4),
                        child: Text(
                          'Updated ${latestMarketPriceUpdate?.toUtc().toIso8601String() ?? 'unknown'}',
                          style: const TextStyle(
                            color: AppColors.textSecondary,
                            fontSize: 10,
                          ),
                        ),
                      ),
                  ],
                ),
              ),
              const SizedBox(height: 16),
            ],

            if (_canPurchase)
              GridView.builder(
                shrinkWrap: true,
                physics: const NeverScrollableScrollPhysics(),
                gridDelegate: const SliverGridDelegateWithFixedCrossAxisCount(
                  crossAxisCount: 2,
                  crossAxisSpacing: 16,
                  mainAxisSpacing: 16,
                  childAspectRatio: 0.85,
                ),
                itemCount: packages.length,
                itemBuilder: (context, index) {
                  final pkg = packages[index];
                  final isPopular = pkg['popular'] as bool;

                  return InkWell(
                    onTap: !_isCreatingOrder
                        ? () => _startCryptoCheckout(
                            authController,
                            pkg['coins'] as int,
                            _selectedPaymentMethod!,
                          )
                        : null,
                    borderRadius: BorderRadius.circular(22),
                    child: Container(
                      decoration: BoxDecoration(
                        gradient: isPopular
                            ? AppColors.softGradient
                            : const LinearGradient(
                                colors: [Colors.white, Colors.white],
                              ),
                        borderRadius: BorderRadius.circular(22),
                        border: Border.all(
                          color: isPopular
                              ? AppColors.primary.withValues(alpha: 0.65)
                              : AppColors.border,
                          width: isPopular ? 1.5 : 1,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: (isPopular
                                    ? AppColors.primary
                                    : Colors.black)
                                .withValues(alpha: isPopular ? 0.12 : 0.04),
                            blurRadius: 14,
                            offset: const Offset(0, 6),
                          ),
                        ],
                      ),
                      child: Stack(
                        children: [
                          if (isPopular)
                            Positioned(
                              top: 0,
                              right: 0,
                              child: Container(
                                padding: const EdgeInsets.symmetric(
                                  horizontal: 11,
                                  vertical: 6,
                                ),
                                decoration: const BoxDecoration(
                                  gradient: AppColors.primaryGradient,
                                  borderRadius: BorderRadius.only(
                                    topRight: Radius.circular(20),
                                    bottomLeft: Radius.circular(12),
                                  ),
                                ),
                                child: const Text(
                                  'POPULAR',
                                  style: TextStyle(
                                    color: Colors.white,
                                    fontSize: 10,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                              ),
                            ),
                          Padding(
                            padding: const EdgeInsets.all(14),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                Container(
                                  width: 52,
                                  height: 52,
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: 0.8),
                                    shape: BoxShape.circle,
                                    boxShadow: [
                                      BoxShadow(
                                        color: AppColors.primary.withValues(
                                          alpha: 0.09,
                                        ),
                                        blurRadius: 10,
                                        offset: const Offset(0, 3),
                                      ),
                                    ],
                                  ),
                                  alignment: Alignment.center,
                                  child: const VpcCoinIcon(size: 38),
                                ),
                                const SizedBox(height: 9),
                                Text(
                                  '${pkg['coins']} Coins',
                                  style: const TextStyle(
                                    fontSize: 17,
                                    fontWeight: FontWeight.bold,
                                    color: AppColors.textPrimary,
                                  ),
                                ),
                                const SizedBox(height: 3),
                                Text(
                                  pkg['label'] as String,
                                  style: const TextStyle(
                                    fontSize: 12,
                                    color: AppColors.textSecondary,
                                  ),
                                ),
                                const SizedBox(height: 10),
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 14,
                                    vertical: 7,
                                  ),
                                  decoration: BoxDecoration(
                                    color: Colors.white.withValues(alpha: 0.9),
                                    borderRadius: BorderRadius.circular(20),
                                    border: Border.all(
                                      color: AppColors.primary.withValues(
                                        alpha: 0.12,
                                      ),
                                    ),
                                  ),
                                  child: Text(
                                    '\$${pkg['price']} USD',
                                    style: const TextStyle(
                                      color: AppColors.primary,
                                      fontWeight: FontWeight.bold,
                                      fontSize: 14,
                                    ),
                                  ),
                                ),
                              ],
                            ),
                          ),
                        ],
                      ),
                    ),
                  );
                },
              ),
            const SizedBox(height: 24),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: AppColors.primary,
                foregroundColor: Colors.white,
                padding: const EdgeInsets.symmetric(vertical: 16),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
                ),
              ),
              onPressed: () => Get.to(() => const StoreScreen()),
              icon: const Icon(Icons.storefront),
              label: const Text(
                'Visit Avatar & Frame Store 💎',
                style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
              ),
            ),
          ],
        ),
      ),
    );
  }

  Future<void> _startCryptoCheckout(
    AuthController authController,
    int coins,
    String paymentMethod,
  ) async {
    if (_isCreatingOrder) return;
    setState(() => _isCreatingOrder = true);

    try {
      final order = await authController.createCryptoOrder(
        coins,
        paymentMethod: paymentMethod,
      );
      final checkoutId = order['checkout_id']?.toString();
      final amount = (order['amount'] ?? order['amount_usdc'])?.toString();
      final network = order['network']?.toString();
      final recipient = order['recipient_address']?.toString();
      final asset = (order['asset'] ?? order['currency'])?.toString();
      final tokenMint = (order['token_mint'] ?? order['token_contract'])
          ?.toString();
      final isSolana = paymentMethod.startsWith('solana_');
      final isTestnet =
          network?.contains('testnet') == true ||
          network == 'Polygon PoS Amoy (testnet)';
      final chainId = int.tryParse(order['chain_id']?.toString() ?? '');
      final isPolygonMainnet =
          paymentMethod == 'polygon_usdc' &&
          asset == 'USDC' &&
          chainId == 137 &&
          network == 'Polygon PoS' &&
          tokenMint?.toLowerCase() ==
              '0x3c499c542cef5e3811e1192ce70d8cc03d5c3359';
      final isPolygonAmoy =
          paymentMethod == 'polygon_usdc' &&
          asset == 'USDC' &&
          chainId == 80002 &&
          network == 'Polygon PoS Amoy (testnet)' &&
          tokenMint?.toLowerCase() ==
              '0x41e94eb019c0762f9bfcf9fb1e58725bfb0e7582';
      final validSolanaMethod =
          isSolana &&
          ((paymentMethod == 'solana_sol' && asset == 'SOL') ||
              (paymentMethod == 'solana_usdc' && asset == 'USDC') ||
              (paymentMethod == 'solana_usdt' && asset == 'USDT')) &&
          (network == 'Solana Mainnet' ||
              network == 'Solana Devnet (testnet)') &&
          (tokenMint == null ||
              RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$').hasMatch(tokenMint));
      final validAmount =
          amount != null &&
          RegExp(
            asset == 'SOL' ? r'^\d+\.\d{9}$' : r'^\d+\.\d{1,6}$',
          ).hasMatch(amount);
      final validRecipient =
          recipient != null &&
          (isSolana
              ? RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$').hasMatch(recipient)
              : RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(recipient));
      if (checkoutId == null ||
          !validAmount ||
          network == null ||
          asset == null ||
          !validRecipient ||
          !(isPolygonMainnet || isPolygonAmoy || validSolanaMethod) ||
          (paymentMethod == 'solana_sol' && tokenMint != null) ||
          (paymentMethod != 'solana_sol' && isSolana && tokenMint == null)) {
        throw StateError('The server returned invalid crypto payment details.');
      }

      final expiresInMinutes =
          int.tryParse(order['expires_in_minutes']?.toString() ?? '') ??
          (int.tryParse(order['expires_in_hours']?.toString() ?? '') ?? 24) *
              60;
      if (!mounted) return;
      _showCryptoPaymentDialog(
        authController,
        checkoutId: checkoutId,
        coins: coins,
        amount: amount,
        asset: asset,
        network: chainId == null ? network : '$network (Chain ID $chainId)',
        tokenMint: tokenMint,
        solPriceUsd: order['sol_price_usd']?.toString(),
        paymentMethod: paymentMethod,
        expiresInMinutes: expiresInMinutes,
        isTestnet: isTestnet,
        recipient: recipient,
      );
    } catch (error) {
      if (mounted) {
        Get.snackbar(
          'Unable to start crypto payment',
          error.toString().replaceFirst('Bad state: ', ''),
          snackPosition: SnackPosition.BOTTOM,
        );
      }
    } finally {
      if (mounted) setState(() => _isCreatingOrder = false);
    }
  }

  void _showCryptoPaymentDialog(
    AuthController authController, {
    required String checkoutId,
    required int coins,
    required String amount,
    required String asset,
    required String network,
    required String paymentMethod,
    required int expiresInMinutes,
    required bool isTestnet,
    required String recipient,
    String? tokenMint,
    String? solPriceUsd,
  }) {
    final isSolana = paymentMethod.startsWith('solana_');
    final validityPeriod = expiresInMinutes >= 60
        ? '${expiresInMinutes ~/ 60} hours'
        : '$expiresInMinutes minutes';
    final transactionHashController = TextEditingController();
    var isVerifying = false;
    Get.dialog(
      StatefulBuilder(
        builder: (dialogContext, setDialogState) {
          return AlertDialog(
            backgroundColor: Colors.white,
            surfaceTintColor: Colors.transparent,
            insetPadding: const EdgeInsets.symmetric(
              horizontal: 20,
              vertical: 24,
            ),
            titlePadding: const EdgeInsets.fromLTRB(20, 20, 20, 0),
            contentPadding: const EdgeInsets.fromLTRB(20, 16, 20, 0),
            actionsPadding: const EdgeInsets.fromLTRB(16, 8, 16, 16),
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(26),
            ),
            title: Row(
              children: [
                Container(
                  width: 44,
                  height: 44,
                  decoration: const BoxDecoration(
                    gradient: AppColors.primaryGradient,
                    shape: BoxShape.circle,
                  ),
                  child: Icon(
                    isSolana
                        ? Icons.bolt_rounded
                        : Icons.account_balance_wallet_rounded,
                    color: Colors.white,
                    size: 23,
                  ),
                ),
                const SizedBox(width: 12),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(
                        'Pay with $asset',
                        style: const TextStyle(
                          color: AppColors.textPrimary,
                          fontSize: 19,
                          fontWeight: FontWeight.bold,
                        ),
                      ),
                      Text(
                        'Crypto checkout details',
                        style: TextStyle(
                          color: AppColors.textSecondary,
                          fontSize: 12,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      gradient: AppColors.softGradient,
                      borderRadius: BorderRadius.circular(18),
                      border: Border.all(
                        color: AppColors.primary.withValues(alpha: 0.1),
                      ),
                    ),
                    child: Row(
                      children: [
                        const VpcCoinIcon(size: 38),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                '$coins Vamper Coins',
                                style: const TextStyle(
                                  fontSize: 15,
                                  fontWeight: FontWeight.bold,
                                  color: AppColors.textPrimary,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                'Send exactly $amount $asset',
                                style: const TextStyle(
                                  fontSize: 14,
                                  color: AppColors.primary,
                                  fontWeight: FontWeight.bold,
                                ),
                              ),
                            ],
                          ),
                        ),
                        IconButton(
                          tooltip: 'Copy exact amount',
                          visualDensity: VisualDensity.compact,
                          onPressed: () async {
                            await Clipboard.setData(
                              ClipboardData(text: amount),
                            );
                          },
                          icon: const Icon(
                            Icons.copy_rounded,
                            size: 19,
                            color: AppColors.primary,
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 12),
                  Container(
                    padding: const EdgeInsets.symmetric(
                      horizontal: 13,
                      vertical: 12,
                    ),
                    decoration: BoxDecoration(
                      color: AppColors.background,
                      borderRadius: BorderRadius.circular(14),
                    ),
                    child: Row(
                      children: [
                        const Icon(
                          Icons.hub_outlined,
                          size: 19,
                          color: AppColors.primary,
                        ),
                        const SizedBox(width: 10),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                network,
                                style: const TextStyle(
                                  color: AppColors.textPrimary,
                                  fontWeight: FontWeight.w600,
                                  fontSize: 12,
                                ),
                              ),
                              const SizedBox(height: 3),
                              Text(
                                'Valid for $validityPeriod · exact amount required',
                                style: const TextStyle(
                                  color: AppColors.textSecondary,
                                  fontSize: 11,
                                ),
                              ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                  if (asset == 'SOL' && solPriceUsd != null) ...[
                    const SizedBox(height: 8),
                    Text(
                      'Order quote: 1 SOL = \$$solPriceUsd USD',
                      style: const TextStyle(
                        color: AppColors.textSecondary,
                        fontSize: 11,
                      ),
                    ),
                  ],
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      const Expanded(
                        child: Text(
                          'Recipient address',
                          style: TextStyle(
                            color: AppColors.textPrimary,
                            fontWeight: FontWeight.w700,
                            fontSize: 13,
                          ),
                        ),
                      ),
                      TextButton.icon(
                        onPressed: () async {
                          await Clipboard.setData(
                            ClipboardData(text: recipient),
                          );
                        },
                        icon: const Icon(Icons.copy_rounded, size: 15),
                        label: const Text('Copy'),
                        style: TextButton.styleFrom(
                          visualDensity: VisualDensity.compact,
                        ),
                      ),
                    ],
                  ),
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: AppColors.background,
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(color: AppColors.border),
                    ),
                    child: SelectableText(
                      recipient,
                      style: const TextStyle(
                        fontSize: 11,
                        height: 1.4,
                        fontFamily: 'monospace',
                        color: AppColors.textPrimary,
                      ),
                    ),
                  ),
                  if (tokenMint != null) ...[
                    const SizedBox(height: 12),
                    Row(
                      children: [
                        const Expanded(
                          child: Text(
                            'Token mint',
                            style: TextStyle(
                              color: AppColors.textPrimary,
                              fontWeight: FontWeight.w700,
                              fontSize: 13,
                            ),
                          ),
                        ),
                        TextButton.icon(
                          onPressed: () async {
                            await Clipboard.setData(
                              ClipboardData(text: tokenMint),
                            );
                          },
                          icon: const Icon(Icons.copy_rounded, size: 15),
                          label: const Text('Copy'),
                          style: TextButton.styleFrom(
                            visualDensity: VisualDensity.compact,
                          ),
                        ),
                      ],
                    ),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: AppColors.background,
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(color: AppColors.border),
                      ),
                      child: SelectableText(
                        tokenMint,
                        style: const TextStyle(
                          fontSize: 11,
                          height: 1.4,
                          fontFamily: 'monospace',
                          color: AppColors.textPrimary,
                        ),
                      ),
                    ),
                  ],
                  const SizedBox(height: 14),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: (isTestnet ? AppColors.error : AppColors.accent)
                          .withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: (isTestnet
                                ? AppColors.error
                                : AppColors.accent)
                            .withValues(alpha: 0.2),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(
                          isTestnet
                              ? Icons.warning_amber_rounded
                              : Icons.info_outline_rounded,
                          size: 18,
                          color: isTestnet
                              ? AppColors.error
                              : AppColors.primary,
                        ),
                        const SizedBox(width: 9),
                        Expanded(
                          child: Text(
                            isTestnet
                                ? 'TESTNET ONLY: Test tokens have no real value. Never send mainnet funds.'
                                : isSolana
                                ? 'Send only $asset on this network. SOL is also needed for network fees. Transfers are usually irreversible.'
                                : 'Send only $asset on this network. POL is also needed for network fees. Transfers are usually irreversible.',
                            style: TextStyle(
                              fontSize: 11,
                              height: 1.35,
                              color: isTestnet
                                  ? AppColors.error
                                  : AppColors.textSecondary,
                              fontWeight: isTestnet
                                  ? FontWeight.w700
                                  : FontWeight.normal,
                            ),
                          ),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: transactionHashController,
                    enabled: !isVerifying,
                    decoration: InputDecoration(
                      labelText: isSolana
                          ? 'Solana transaction signature'
                          : 'Transaction hash',
                      hintText: isSolana ? 'Base58 signature' : '0x...',
                      prefixIcon: const Icon(
                        Icons.receipt_long_outlined,
                        color: AppColors.primary,
                      ),
                      filled: true,
                      fillColor: Colors.white,
                      contentPadding: const EdgeInsets.symmetric(
                        horizontal: 14,
                        vertical: 15,
                      ),
                      enabledBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide: const BorderSide(color: AppColors.border),
                      ),
                      focusedBorder: OutlineInputBorder(
                        borderRadius: BorderRadius.circular(14),
                        borderSide: const BorderSide(
                          color: AppColors.primary,
                          width: 1.5,
                        ),
                      ),
                    ),
                    textInputAction: TextInputAction.done,
                  ),
                  const SizedBox(height: 8),
                  const Text(
                    'After sending, paste the transaction signature here. Coins are added only after the server verifies the finalized transfer.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  if (isVerifying) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(11),
                      decoration: BoxDecoration(
                        color: AppColors.primary.withValues(alpha: 0.07),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: const Row(
                        children: [
                          SizedBox(
                            width: 17,
                            height: 17,
                            child: CircularProgressIndicator(
                              strokeWidth: 2,
                              color: AppColors.primary,
                            ),
                          ),
                          SizedBox(width: 10),
                          Expanded(
                            child: Text(
                              'Checking with the network. Please do not send the payment again.',
                              style: TextStyle(
                                fontSize: 11,
                                color: AppColors.textSecondary,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: isVerifying
                    ? null
                    : () {
                        Get.back();
                      },
                style: TextButton.styleFrom(
                  foregroundColor: AppColors.textSecondary,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 16,
                    vertical: 13,
                  ),
                ),
                child: const Text('Close'),
              ),
              ElevatedButton(
                onPressed: isVerifying
                    ? null
                    : () async {
                        final transactionHash = transactionHashController.text
                            .trim();
                        final isValidSignature = isSolana
                            ? RegExp(
                                r'^[1-9A-HJ-NP-Za-km-z]{64,100}$',
                              ).hasMatch(transactionHash)
                            : RegExp(
                                r'^0x[a-fA-F0-9]{64}$',
                              ).hasMatch(transactionHash);
                        if (!isValidSignature) {
                          Get.snackbar(
                            'Invalid transaction signature',
                            'Paste the full transaction signature from your wallet.',
                            snackPosition: SnackPosition.BOTTOM,
                          );
                          return;
                        }
                        setDialogState(() => isVerifying = true);
                        try {
                          final result = await authController
                              .verifyCryptoPayment(
                                checkoutId,
                                transactionHash,
                                isSolana: isSolana,
                              );
                          if (!dialogContext.mounted) return;
                          if (result['status'] == 'completed') {
                            Get.back();
                          } else {
                            setDialogState(() => isVerifying = false);
                          }
                        } catch (error) {
                          debugPrint(
                            'Crypto payment verification UI failed: $error',
                          );
                        } finally {
                          if (dialogContext.mounted && isVerifying) {
                            setDialogState(() => isVerifying = false);
                          }
                        }
                      },
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.primary,
                  foregroundColor: Colors.white,
                  elevation: 0,
                  padding: const EdgeInsets.symmetric(
                    horizontal: 18,
                    vertical: 13,
                  ),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(14),
                  ),
                ),
                child: isVerifying
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                          strokeWidth: 2,
                          color: Colors.white,
                        ),
                      )
                    : const Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          Icon(Icons.verified_rounded, size: 18),
                          SizedBox(width: 7),
                          Text(
                            'Verify payment',
                            style: TextStyle(fontWeight: FontWeight.bold),
                          ),
                        ],
                      ),
              ),
            ],
          );
        },
      ),
      barrierDismissible: false,
    ).whenComplete(transactionHashController.dispose);
  }
}
