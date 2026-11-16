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
  final List<Map<String, dynamic>> _paymentMethods = [];
  String? _selectedPaymentMethod;
  bool _isLoadingPaymentSettings = true;
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
        backgroundColor: Colors.white,
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
        padding: const EdgeInsets.all(20),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            // Current Balance Card
            Container(
              padding: const EdgeInsets.all(24),
              decoration: BoxDecoration(
                gradient: AppColors.primaryGradient,
                borderRadius: BorderRadius.circular(24),
                boxShadow: [
                  BoxShadow(
                    color: AppColors.primary.withValues(alpha: 0.3),
                    blurRadius: 15,
                    offset: const Offset(0, 8),
                  ),
                ],
              ),
              child: Row(
                mainAxisAlignment: MainAxisAlignment.spaceBetween,
                children: [
                  Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'Current Balance',
                        style: TextStyle(color: Colors.white70, fontSize: 14),
                      ),
                      const SizedBox(height: 8),
                      Obx(
                        () => Text(
                          '${authController.currentUser.value?.coins ?? 0} Coins',
                          style: const TextStyle(
                            color: Colors.white,
                            fontSize: 28,
                            fontWeight: FontWeight.bold,
                          ),
                        ),
                      ),
                    ],
                  ),
                  Container(
                    padding: const EdgeInsets.all(12),
                    decoration: BoxDecoration(
                      color: Colors.white.withValues(alpha: 0.2),
                      shape: BoxShape.circle,
                    ),
                    child: const VpcCoinIcon(size: 40),
                  ),
                ],
              ),
            ),
            const SizedBox(height: 16),
            ElevatedButton.icon(
              style: ElevatedButton.styleFrom(
                backgroundColor: Colors.white,
                foregroundColor: AppColors.primary,
                side: const BorderSide(color: AppColors.primary, width: 1.5),
                padding: const EdgeInsets.symmetric(vertical: 14),
                shape: RoundedRectangleBorder(
                  borderRadius: BorderRadius.circular(16),
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
            const Text(
              'Select Coin Package',
              style: TextStyle(
                fontSize: 18,
                fontWeight: FontWeight.bold,
                color: AppColors.textPrimary,
              ),
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
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.red.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(12),
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
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.blue.withValues(alpha: 0.08),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: Text(
                  _isSelectedTestnet
                      ? 'TESTNET ONLY: Test tokens have no real value. Use a staging account and the selected test network only; never send mainnet funds.'
                      : 'Choose a payment asset below. Only the exact amount, network, token, and recipient shown in checkout will be accepted.',
                  style: TextStyle(
                    color: _isSelectedTestnet
                        ? AppColors.error
                        : AppColors.textSecondary,
                    fontSize: 12,
                    fontWeight: _isSelectedTestnet
                        ? FontWeight.bold
                        : FontWeight.normal,
                  ),
                ),
              ),
            ] else ...[
              Container(
                padding: const EdgeInsets.all(12),
                margin: const EdgeInsets.only(bottom: 16),
                decoration: BoxDecoration(
                  color: Colors.orange.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(12),
                ),
                child: const Text(
                  'Crypto top-ups are not available yet. Please try again later.',
                  style: TextStyle(
                    color: AppColors.textSecondary,
                    fontSize: 12,
                  ),
                ),
              ),
            ],

            if (_canPurchase) ...[
              DropdownButtonFormField<String>(
                value: _selectedPaymentMethod,
                decoration: const InputDecoration(
                  labelText: 'Payment method',
                  border: OutlineInputBorder(),
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
                    borderRadius: BorderRadius.circular(20),
                    child: Container(
                      decoration: BoxDecoration(
                        color: Colors.white,
                        borderRadius: BorderRadius.circular(20),
                        border: Border.all(
                          color: isPopular
                              ? AppColors.primary
                              : AppColors.border,
                          width: isPopular ? 2 : 1,
                        ),
                        boxShadow: [
                          BoxShadow(
                            color: Colors.black.withValues(alpha: 0.05),
                            blurRadius: 10,
                            offset: const Offset(0, 4),
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
                                  horizontal: 10,
                                  vertical: 4,
                                ),
                                decoration: const BoxDecoration(
                                  color: AppColors.primary,
                                  borderRadius: BorderRadius.only(
                                    topRight: Radius.circular(18),
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
                            padding: const EdgeInsets.all(16),
                            child: Column(
                              mainAxisAlignment: MainAxisAlignment.center,
                              children: [
                                const VpcCoinIcon(size: 44),
                                const SizedBox(height: 12),
                                Text(
                                  '${pkg['coins']} Coins',
                                  style: const TextStyle(
                                    fontSize: 18,
                                    fontWeight: FontWeight.bold,
                                    color: AppColors.textPrimary,
                                  ),
                                ),
                                const SizedBox(height: 4),
                                Text(
                                  pkg['label'] as String,
                                  style: const TextStyle(
                                    fontSize: 12,
                                    color: AppColors.textLight,
                                  ),
                                ),
                                const SizedBox(height: 12),
                                Container(
                                  padding: const EdgeInsets.symmetric(
                                    horizontal: 16,
                                    vertical: 8,
                                  ),
                                  decoration: BoxDecoration(
                                    color: AppColors.primary.withValues(
                                      alpha: 0.1,
                                    ),
                                    borderRadius: BorderRadius.circular(12),
                                  ),
                                  child: Text(
                                    '${pkg['price']} USD',
                                    style: const TextStyle(
                                      color: AppColors.primary,
                                      fontWeight: FontWeight.bold,
                                      fontSize: 16,
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
        builder: (context, setDialogState) {
          return AlertDialog(
            shape: RoundedRectangleBorder(
              borderRadius: BorderRadius.circular(20),
            ),
            title: Row(
              children: [
                const Icon(
                  Icons.account_balance_wallet_outlined,
                  color: AppColors.primary,
                ),
                const SizedBox(width: 8),
                Text('Pay with $asset'),
              ],
            ),
            content: SingleChildScrollView(
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    '$coins Vamper Coins',
                    style: const TextStyle(
                      fontSize: 20,
                      fontWeight: FontWeight.bold,
                      color: AppColors.textPrimary,
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text(
                    'Send exactly $amount $asset',
                    style: const TextStyle(
                      fontSize: 16,
                      color: AppColors.primary,
                      fontWeight: FontWeight.bold,
                    ),
                  ),
                  Text(
                    'Order-specific amount; valid for $validityPeriod. Send the exact displayed amount.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: () async {
                        await Clipboard.setData(ClipboardData(text: amount));
                        Get.snackbar(
                          'Copied',
                          'Exact $asset amount copied.',
                          snackPosition: SnackPosition.BOTTOM,
                        );
                      },
                      icon: const Icon(Icons.copy, size: 16),
                      label: const Text('Copy amount'),
                    ),
                  ),
                  const SizedBox(height: 8),
                  Text('Network: $network'),
                  if (asset == 'SOL' && solPriceUsd != null) ...[
                    const SizedBox(height: 4),
                    Text('Locked quote: 1 SOL = $solPriceUsd USD'),
                  ],
                  const SizedBox(height: 12),
                  const Text(
                    'Recipient address',
                    style: TextStyle(fontWeight: FontWeight.w600),
                  ),
                  const SizedBox(height: 4),
                  SelectableText(
                    recipient,
                    style: const TextStyle(fontSize: 12),
                  ),
                  Align(
                    alignment: Alignment.centerRight,
                    child: TextButton.icon(
                      onPressed: () async {
                        await Clipboard.setData(ClipboardData(text: recipient));
                        Get.snackbar(
                          'Copied',
                          'Recipient address copied.',
                          snackPosition: SnackPosition.BOTTOM,
                        );
                      },
                      icon: const Icon(Icons.copy, size: 16),
                      label: const Text('Copy address'),
                    ),
                  ),
                  if (tokenMint != null) ...[
                    const Text(
                      'Token mint',
                      style: TextStyle(fontWeight: FontWeight.w600),
                    ),
                    const SizedBox(height: 4),
                    SelectableText(
                      tokenMint,
                      style: const TextStyle(fontSize: 12),
                    ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton.icon(
                        onPressed: () async {
                          await Clipboard.setData(
                            ClipboardData(text: tokenMint),
                          );
                          Get.snackbar(
                            'Copied',
                            'Token mint copied.',
                            snackPosition: SnackPosition.BOTTOM,
                          );
                        },
                        icon: const Icon(Icons.copy, size: 16),
                        label: const Text('Copy mint'),
                      ),
                    ),
                  ],
                  Text(
                    isTestnet
                        ? 'TESTNET ONLY: Test tokens have no real value. Do not send mainnet funds. Use only the selected test network.'
                        : isSolana
                        ? 'Send only $asset on $network. The wallet also needs SOL for network fees. Blockchain transfers cannot usually be reversed.'
                        : 'Send only $asset on $network. The wallet also needs POL for network fees. Blockchain transfers cannot usually be reversed.',
                    style: TextStyle(
                      fontSize: 12,
                      color: isTestnet
                          ? AppColors.error
                          : AppColors.textSecondary,
                      fontWeight: isTestnet
                          ? FontWeight.bold
                          : FontWeight.normal,
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
                      border: OutlineInputBorder(),
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
                          if (result['status'] == 'completed') {
                            Get.back();
                          } else {
                            setDialogState(() => isVerifying = false);
                          }
                        } catch (_) {
                          setDialogState(() => isVerifying = false);
                        }
                      },
                style: ElevatedButton.styleFrom(
                  backgroundColor: AppColors.success,
                  foregroundColor: Colors.white,
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
                    : const Text('Verify transaction'),
              ),
            ],
          );
        },
      ),
      barrierDismissible: false,
    ).whenComplete(transactionHashController.dispose);
  }
}
