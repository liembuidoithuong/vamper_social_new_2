import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:get/get.dart';
import '../../app/theme/app_colors.dart';
import '../../controllers/auth_controller.dart';
import '../../core/constants/api_constants.dart';
import '../../core/utils/date_formatter.dart';
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
  String? _openCryptoOrdersError;
  String? _openCryptoOrdersMoreError;
  final List<Map<String, dynamic>> _paymentMethods = [];
  final List<Map<String, dynamic>> _openCryptoOrders = [];
  final Map<String, Map<String, String>> _marketPrices = {};
  String? _selectedPaymentMethod;
  bool _isLoadingPaymentSettings = true;
  bool _isRefreshingMarketPrices = false;
  bool _isLoadingOpenCryptoOrders = false;
  bool _isLoadingMoreOpenCryptoOrders = false;
  bool _hasMoreOpenCryptoOrders = true;
  bool _isCreatingOrder = false;
  int _nextOpenCryptoOrdersOffset = 0;
  int _activeOpenCryptoOrderCount = 0;
  String? _deletingCryptoOrderId;
  BuildContext? _openCryptoOrdersSheetContext;
  StateSetter? _openCryptoOrdersSheetStateSetter;

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
      _selectedMethod?['is_testnet'] == true ||
      (_selectedMethod?['network']?.toString() ?? '').contains('testnet');

  Map<String, dynamic>? _findPaymentMethod(String paymentMethod) {
    for (final method in _paymentMethods) {
      if (method['id'] == paymentMethod) return method;
    }
    return null;
  }

  bool _isSolanaMethod(String paymentMethod, {String? assetType}) {
    final selectedType = _findPaymentMethod(paymentMethod)?['asset_type']
        ?.toString();
    return paymentMethod.startsWith('solana_') ||
        assetType?.startsWith('solana_') == true ||
        selectedType?.startsWith('solana_') == true;
  }

  @override
  void initState() {
    super.initState();
    _loadPaymentSettings();
    _loadMarketPrices();
    _loadOpenCryptoOrders();
  }

  Future<void> _loadOpenCryptoOrders({bool loadMore = false}) async {
    if (!mounted ||
        (loadMore &&
            (!_hasMoreOpenCryptoOrders ||
                _isLoadingOpenCryptoOrders ||
                _isLoadingMoreOpenCryptoOrders)) ||
        (!loadMore && _isLoadingMoreOpenCryptoOrders)) {
      return;
    }
    setState(() {
      if (loadMore) {
        _isLoadingMoreOpenCryptoOrders = true;
      }
      if (!loadMore) _isLoadingOpenCryptoOrders = true;
      if (loadMore) {
        _openCryptoOrdersMoreError = null;
      } else {
        _openCryptoOrdersError = null;
        _openCryptoOrdersMoreError = null;
      }
    });

    try {
      final response = await Get.find<AuthController>().apiService.get(
        ApiConstants.getOpenCryptoOrders,
        params: {'offset': loadMore ? _nextOpenCryptoOrdersOffset : 0},
      );
      if (response.data['success'] != true) {
        throw StateError(
          response.data['message']?.toString() ??
              'Unable to load open crypto checkouts.',
        );
      }

      final data = Map<String, dynamic>.from(response.data['data'] as Map);
      final rawOrders = data['orders'];
      final activeCount = int.tryParse(data['active_count']?.toString() ?? '');
      if (rawOrders is! List ||
          data['has_more'] is! bool ||
          activeCount == null) {
        throw StateError('The open checkout response is invalid.');
      }
      final orders = <Map<String, dynamic>>[];
      for (final rawOrder in rawOrders) {
        if (rawOrder is! Map) {
          throw StateError('The open checkout response is invalid.');
        }
        final order = Map<String, dynamic>.from(rawOrder);
        final method = order['payment_method']?.toString();
        final asset = order['asset']?.toString();
        final status = order['status']?.toString();
        final isValidMethod =
            (method == 'polygon_usdc' && asset == 'USDC') ||
            (method == 'solana_sol' && asset == 'SOL') ||
            (method == 'solana_usdc' && asset == 'USDC') ||
            (method == 'solana_usdt' && asset == 'USDT') ||
            (method?.startsWith('cfg_') == true &&
                RegExp(r'^[A-Z0-9]{2,20}$').hasMatch(asset ?? '') &&
                [
                  'evm_native',
                  'evm_token',
                  'solana_native',
                  'solana_spl',
                ].contains(order['asset_type']) &&
                int.tryParse(order['decimals']?.toString() ?? '') != null);
        if (!isValidMethod ||
            !['pending', 'expired', 'completed'].contains(status) ||
            order['checkout_id'] == null ||
            int.tryParse(order['coins']?.toString() ?? '') == null ||
            order['amount'] == null ||
            order['network'] == null ||
            order['recipient_address'] == null) {
          throw StateError('The open checkout response is invalid.');
        }
        orders.add(order);
      }

      if (!mounted) return;
      setState(() {
        if (loadMore) {
          final existingIds = _openCryptoOrders
              .map((order) => order['checkout_id'])
              .toSet();
          _openCryptoOrders.addAll(
            orders.where(
              (order) => !existingIds.contains(order['checkout_id']),
            ),
          );
        } else {
          _openCryptoOrders
            ..clear()
            ..addAll(orders);
          _nextOpenCryptoOrdersOffset = 0;
        }
        _nextOpenCryptoOrdersOffset += orders.length;
        _hasMoreOpenCryptoOrders = data['has_more'] as bool;
        _activeOpenCryptoOrderCount = activeCount;
      });
    } catch (error) {
      if (!mounted) return;
      setState(() {
        final message = error.toString().replaceFirst('Bad state: ', '');
        if (loadMore) {
          _openCryptoOrdersMoreError = message;
        } else {
          _openCryptoOrdersError = message;
        }
      });
    } finally {
      if (mounted) {
        setState(() {
          _isLoadingOpenCryptoOrders = false;
          _isLoadingMoreOpenCryptoOrders = false;
        });
        _notifyOpenCryptoOrdersSheet();
      }
    }
  }

  void _notifyOpenCryptoOrdersSheet() {
    final sheetContext = _openCryptoOrdersSheetContext;
    final setSheetState = _openCryptoOrdersSheetStateSetter;
    if (sheetContext != null && sheetContext.mounted && setSheetState != null) {
      setSheetState(() {});
    }
  }

  Future<void> _confirmAndDeleteCryptoOrder(
    BuildContext sheetContext,
    AuthController authController,
    Map<String, dynamic> order,
    StateSetter setSheetState,
  ) async {
    final checkoutId = order['checkout_id']?.toString();
    if (checkoutId == null ||
        _isLoadingOpenCryptoOrders ||
        _isLoadingMoreOpenCryptoOrders ||
        _deletingCryptoOrderId != null) {
      return;
    }

    final shouldDelete = await showDialog<bool>(
      context: sheetContext,
      builder: (dialogContext) => AlertDialog(
        title: const Text('Remove this checkout?'),
        content: const Text(
          'This removes the checkout from your list and it cannot be reopened. '
          'If you have already sent funds or started an on-chain payment, do '
          'not remove it—your VPC may not be credited.',
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, false),
            child: const Text('Keep order'),
          ),
          TextButton(
            onPressed: () => Navigator.pop(dialogContext, true),
            style: TextButton.styleFrom(foregroundColor: AppColors.error),
            child: const Text('Remove order'),
          ),
        ],
      ),
    );
    if (shouldDelete != true ||
        !mounted ||
        _isLoadingOpenCryptoOrders ||
        _isLoadingMoreOpenCryptoOrders ||
        _deletingCryptoOrderId != null) {
      return;
    }

    setState(() => _deletingCryptoOrderId = checkoutId);
    if (sheetContext.mounted) setSheetState(() {});
    try {
      final response = await authController.apiService.post(
        ApiConstants.deleteOpenCryptoOrder,
        data: {'checkout_id': checkoutId},
      );
      if (response.data['success'] != true) {
        throw StateError(
          response.data['message']?.toString() ??
              'Unable to remove this checkout.',
        );
      }
      final wasActive = response.data['was_active'] == true;
      if (!mounted) return;
      setState(() {
        _openCryptoOrders.removeWhere(
          (existing) => existing['checkout_id'] == checkoutId,
        );
        if (_nextOpenCryptoOrdersOffset > 0) {
          _nextOpenCryptoOrdersOffset--;
        }
        if (wasActive && _activeOpenCryptoOrderCount > 0) {
          _activeOpenCryptoOrderCount--;
        }
      });
      Get.snackbar(
        'Checkout removed',
        'The open top-up was removed.',
        snackPosition: SnackPosition.BOTTOM,
      );
    } catch (error) {
      Get.snackbar(
        'Unable to remove checkout',
        error.toString().replaceFirst('Bad state: ', ''),
        snackPosition: SnackPosition.BOTTOM,
      );
    } finally {
      if (mounted) setState(() => _deletingCryptoOrderId = null);
      if (sheetContext.mounted) setSheetState(() {});
    }
  }

  Future<void> _showOpenCryptoOrdersSheet(
    AuthController authController,
  ) async {
    final selectedOrder = await showModalBottomSheet<Map<String, dynamic>>(
      context: context,
      isScrollControlled: true,
      backgroundColor: Colors.transparent,
      builder: (sheetContext) {
        return StatefulBuilder(
          builder: (sheetBuilderContext, setSheetState) {
            _openCryptoOrdersSheetContext = sheetContext;
            _openCryptoOrdersSheetStateSetter = setSheetState;
            return SafeArea(
              child: Container(
                height: MediaQuery.sizeOf(sheetBuilderContext).height * 0.78,
                decoration: const BoxDecoration(
                  color: AppColors.background,
                  borderRadius: BorderRadius.vertical(
                    top: Radius.circular(26),
                  ),
                ),
                child: Column(
                  children: [
                    Padding(
                      padding: const EdgeInsets.fromLTRB(20, 14, 12, 8),
                      child: Row(
                        children: [
                          const Expanded(
                            child: Column(
                              crossAxisAlignment: CrossAxisAlignment.start,
                              children: [
                                Text(
                                  'Top-up history',
                                  style: TextStyle(
                                    color: AppColors.textPrimary,
                                    fontSize: 19,
                                    fontWeight: FontWeight.bold,
                                  ),
                                ),
                                SizedBox(height: 3),
                                Text(
                                  'Recent payments and open checkouts',
                                  style: TextStyle(
                                    color: AppColors.textSecondary,
                                    fontSize: 12,
                                  ),
                                ),
                              ],
                            ),
                          ),
                          IconButton(
                            tooltip: 'Refresh top-ups',
                            onPressed:
                                _isLoadingOpenCryptoOrders ||
                                    _isLoadingMoreOpenCryptoOrders
                                ? null
                                : () async {
                                    setSheetState(() {});
                                    await _loadOpenCryptoOrders();
                                    if (sheetContext.mounted) {
                                      setSheetState(() {});
                                    }
                                  },
                            icon: _isLoadingOpenCryptoOrders
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
                          IconButton(
                            tooltip: 'Close',
                            onPressed: () => Navigator.pop(sheetContext),
                            icon: const Icon(Icons.close_rounded),
                          ),
                        ],
                      ),
                    ),
                    const Divider(height: 1),
                    Expanded(
                      child: _isLoadingOpenCryptoOrders &&
                              _openCryptoOrders.isEmpty
                          ? const Center(
                              child: CircularProgressIndicator(
                                color: AppColors.primary,
                              ),
                            )
                          : _openCryptoOrdersError != null &&
                                _openCryptoOrders.isEmpty
                          ? _buildOpenOrdersError(
                              onRetry: () async {
                                final refresh = _loadOpenCryptoOrders();
                                setSheetState(() {});
                                await refresh;
                                if (sheetContext.mounted) {
                                  setSheetState(() {});
                                }
                              },
                            )
                          : _openCryptoOrders.isEmpty
                          ? _buildNoOpenOrders()
                          : NotificationListener<ScrollNotification>(
                              onNotification: (notification) {
                                if (notification.metrics.extentAfter < 240 &&
                                    _hasMoreOpenCryptoOrders &&
                                    !_isLoadingOpenCryptoOrders &&
                                    !_isLoadingMoreOpenCryptoOrders &&
                                    _openCryptoOrdersMoreError == null) {
                                  final loadMore = _loadOpenCryptoOrders(
                                    loadMore: true,
                                  );
                                  setSheetState(() {});
                                  loadMore.whenComplete(() {
                                    if (sheetContext.mounted) {
                                      setSheetState(() {});
                                    }
                                  });
                                }
                                return false;
                              },
                              child: ListView.separated(
                                padding: const EdgeInsets.all(16),
                                itemCount:
                                    _openCryptoOrders.length +
                                    (_hasMoreOpenCryptoOrders ||
                                            _isLoadingMoreOpenCryptoOrders ||
                                            _openCryptoOrdersMoreError != null
                                        ? 1
                                        : 0),
                                separatorBuilder: (context, index) =>
                                    const SizedBox(height: 10),
                                itemBuilder: (context, index) {
                                  if (index >= _openCryptoOrders.length) {
                                    if (_isLoadingMoreOpenCryptoOrders) {
                                      return const Padding(
                                        padding: EdgeInsets.all(16),
                                        child: Center(
                                          child: CircularProgressIndicator(
                                            color: AppColors.primary,
                                            strokeWidth: 2,
                                          ),
                                        ),
                                      );
                                    }
                                    if (_openCryptoOrdersMoreError != null) {
                                      return Padding(
                                        padding: const EdgeInsets.all(12),
                                        child: Column(
                                          children: [
                                            Text(
                                              'Could not load more top-ups: '
                                              '$_openCryptoOrdersMoreError',
                                              textAlign: TextAlign.center,
                                              style: const TextStyle(
                                                color:
                                                    AppColors.textSecondary,
                                                fontSize: 12,
                                              ),
                                            ),
                                            TextButton.icon(
                                              onPressed: () async {
                                                final loadMore =
                                                    _loadOpenCryptoOrders(
                                                      loadMore: true,
                                                    );
                                                setSheetState(() {});
                                                await loadMore;
                                                if (sheetContext.mounted) {
                                                  setSheetState(() {});
                                                }
                                              },
                                              icon: const Icon(
                                                Icons.refresh_rounded,
                                              ),
                                              label: const Text('Try again'),
                                            ),
                                          ],
                                        ),
                                      );
                                    }
                                    return const SizedBox(height: 8);
                                  }
                                  final order = _openCryptoOrders[index];
                                  return _buildOpenOrderTile(
                                    order,
                                    isDeleting:
                                        order['status'] != 'completed' &&
                                        _deletingCryptoOrderId ==
                                        order['checkout_id']?.toString(),
                                    onDelete: order['status'] == 'completed'
                                        ? () {}
                                        : () => _confirmAndDeleteCryptoOrder(
                                            sheetContext,
                                            authController,
                                            order,
                                            setSheetState,
                                          ),
                                    onTap: order['status'] == 'completed'
                                        ? null
                                        : () => Navigator.pop(
                                            sheetContext,
                                            order,
                                          ),
                                  );
                                },
                              ),
                          ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );

    _openCryptoOrdersSheetContext = null;
    _openCryptoOrdersSheetStateSetter = null;
    if (selectedOrder != null && mounted) {
      _resumeCryptoOrder(authController, selectedOrder);
    }
  }

  Widget _buildOpenOrdersError({required VoidCallback onRetry}) {
    return Center(
      child: Padding(
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            const Icon(
              Icons.cloud_off_outlined,
              color: AppColors.error,
              size: 36,
            ),
            const SizedBox(height: 12),
            Text(
              'Could not load top-ups: $_openCryptoOrdersError',
              textAlign: TextAlign.center,
              style: const TextStyle(
                color: AppColors.textSecondary,
                fontSize: 13,
              ),
            ),
            const SizedBox(height: 12),
            OutlinedButton.icon(
              onPressed: onRetry,
              icon: const Icon(Icons.refresh_rounded),
              label: const Text('Try again'),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildNoOpenOrders() {
    return const Center(
      child: Padding(
        padding: EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            Icon(
              Icons.receipt_long_outlined,
              color: AppColors.textLight,
              size: 42,
            ),
            SizedBox(height: 12),
            Text(
              'No top-ups yet',
              style: TextStyle(
                color: AppColors.textPrimary,
                fontWeight: FontWeight.w700,
                fontSize: 15,
              ),
            ),
            SizedBox(height: 5),
            Text(
              'Your completed and open top-ups will appear here.',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: AppColors.textSecondary,
                fontSize: 12,
              ),
            ),
          ],
        ),
      ),
    );
  }

  Widget _buildOpenOrderTile(
    Map<String, dynamic> order, {
    required VoidCallback? onTap,
    required VoidCallback onDelete,
    required bool isDeleting,
  }) {
    final completed = order['status'] == 'completed';
    final expired = !completed && order['is_expired'] == true;
    final coins = order['coins'];
    final asset = order['asset'];
    final amount = order['amount'];
    final network = order['network'];
    final completedAtEpoch = int.tryParse(
      order['completed_at_epoch']?.toString() ?? '',
    );
    final completedAt = completedAtEpoch == null
        ? null
        : DateTime.fromMillisecondsSinceEpoch(completedAtEpoch * 1000);
    final statusColor = expired
        ? AppColors.error
        : completed
        ? AppColors.success
        : AppColors.primary;
    final statusIcon = completed
        ? Icons.check_circle_outline_rounded
        : expired
        ? Icons.history_rounded
        : Icons.account_balance_wallet_outlined;
    final expiresInMinutes =
        int.tryParse(order['expires_in_minutes']?.toString() ?? '') ?? 0;
    final expiresLabel = expiresInMinutes >= 60
        ? expiresInMinutes % 60 == 0
              ? '${expiresInMinutes ~/ 60}h'
              : '${expiresInMinutes ~/ 60}h ${expiresInMinutes % 60}m'
        : '$expiresInMinutes min';
    final creditedTimeLabel = completedAt == null
        ? ''
        : ' ${DateFormatter.formatTimeAgo(completedAt)}';
    final statusLabel = completed
        ? '$network · Credited$creditedTimeLabel'
        : expired
        ? '$network · Expired — verify only if already paid'
        : '$network · Expires in $expiresLabel';
    final statusTextColor = completed
        ? AppColors.success
        : expired
        ? AppColors.error
        : AppColors.textSecondary;

    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(
              color: statusColor.withValues(alpha: 0.16),
            ),
          ),
          child: Row(
            children: [
              Container(
                width: 40,
                height: 40,
                decoration: BoxDecoration(
                  color: statusColor.withValues(alpha: 0.09),
                  shape: BoxShape.circle,
                ),
                child: Icon(statusIcon, size: 20, color: statusColor),
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(
                      '$coins VPC · $amount $asset',
                      style: const TextStyle(
                        color: AppColors.textPrimary,
                        fontSize: 13,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      statusLabel,
                      style: TextStyle(
                        color: statusTextColor,
                        fontSize: 11,
                        height: 1.3,
                      ),
                    ),
                  ],
                ),
              ),
              Row(
                mainAxisSize: MainAxisSize.min,
                children: [
                  if (!completed) ...[
                    IconButton(
                      tooltip: 'Remove checkout',
                      visualDensity: VisualDensity.compact,
                      onPressed: isDeleting ? null : onDelete,
                      icon: isDeleting
                          ? const SizedBox(
                              width: 16,
                              height: 16,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(
                              Icons.close_rounded,
                              color: AppColors.error,
                              size: 19,
                            ),
                    ),
                    const SizedBox(width: 2),
                  ],
                  Text(
                    completed ? 'Done' : expired ? 'Verify' : 'Resume',
                    style: TextStyle(
                      color: expired
                          ? AppColors.error
                          : completed
                          ? AppColors.success
                          : AppColors.primary,
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ],
          ),
        ),
      ),
    );
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
          final assetType = method['asset_type']?.toString();
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
          final chainId = int.tryParse(method['chain_id']?.toString() ?? '');
          final decimals = int.tryParse(method['decimals']?.toString() ?? '');
          final validConfiguredMethod =
              id?.startsWith('cfg_') == true &&
              RegExp(r'^[A-Z0-9]{2,20}$').hasMatch(asset ?? '') &&
              [
                'evm_native',
                'evm_token',
                'solana_native',
                'solana_spl',
              ].contains(assetType) &&
              methodNetwork != null &&
              methodNetwork.isNotEmpty &&
              decimals != null &&
              decimals >= 0 &&
              decimals <= 18 &&
              (assetType?.startsWith('evm_') == true
                  ? chainId != null && chainId > 0
                  : chainId == null);
          if (!isValidPolygon &&
              !(isValidSolana && validSolanaNetwork) &&
              !validConfiguredMethod) {
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
        actions: [
          Stack(
            clipBehavior: Clip.none,
            children: [
              IconButton(
                tooltip: 'My top-ups',
                onPressed: () => _showOpenCryptoOrdersSheet(authController),
                icon: const Icon(
                  Icons.receipt_long_outlined,
                  color: AppColors.primary,
                ),
              ),
              if (_activeOpenCryptoOrderCount > 0)
                Positioned(
                  top: 5,
                  right: 4,
                  child: Container(
                    constraints: const BoxConstraints(
                      minWidth: 17,
                      minHeight: 17,
                    ),
                    padding: const EdgeInsets.symmetric(horizontal: 4),
                    decoration: const BoxDecoration(
                      color: AppColors.error,
                      shape: BoxShape.circle,
                    ),
                    alignment: Alignment.center,
                    child: Text(
                      _activeOpenCryptoOrderCount > 9
                          ? '9+'
                          : '$_activeOpenCryptoOrderCount',
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 9,
                        fontWeight: FontWeight.bold,
                      ),
                    ),
                  ),
                ),
            ],
          ),
        ],
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
      final assetType = order['asset_type']?.toString();
      final tokenMint = (order['token_mint'] ?? order['token_contract'])
          ?.toString();
      final decimals =
          int.tryParse(order['decimals']?.toString() ?? '') ??
          (asset == 'SOL' ? 9 : 6);
      final validDecimals = decimals >= 0 && decimals <= 18;
      final amountDecimals = validDecimals ? decimals : 0;
      final isSolana = _isSolanaMethod(
        paymentMethod,
        assetType: assetType,
      );
      final configuredMethod = _findPaymentMethod(paymentMethod);
      final isTestnet =
          order['is_testnet'] == true ||
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
      final isConfiguredMethod = paymentMethod.startsWith('cfg_');
      final validConfiguredMethod =
          isConfiguredMethod &&
          configuredMethod != null &&
          order['method_id'] == paymentMethod &&
          assetType == configuredMethod['asset_type'] &&
          asset == configuredMethod['asset'] &&
          RegExp(r'^[A-Z0-9]{2,20}$').hasMatch(asset ?? '') &&
          network == configuredMethod['network'] &&
          isTestnet == (configuredMethod['is_testnet'] == true) &&
          validDecimals &&
          (assetType?.startsWith('evm_') == true
              ? chainId != null &&
                  chainId > 0 &&
                  chainId ==
                      int.tryParse(
                        configuredMethod['chain_id']?.toString() ?? '',
                      )
              : chainId == null) &&
          (assetType == 'evm_token' || assetType == 'solana_spl'
              ? tokenMint != null &&
                  (assetType == 'evm_token'
                      ? RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(tokenMint)
                      : RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$')
                          .hasMatch(tokenMint))
              : tokenMint == null);
      final amountPattern = amountDecimals == 0
          ? RegExp(r'^\d+$')
          : RegExp('^\\d+\\.\\d{1,$amountDecimals}\$');
      final validAmount =
          validDecimals && amount != null && amountPattern.hasMatch(amount);
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
          !(isPolygonMainnet ||
              isPolygonAmoy ||
              validSolanaMethod ||
              validConfiguredMethod) ||
          (paymentMethod == 'solana_sol' && tokenMint != null) ||
          (['solana_usdc', 'solana_usdt'].contains(paymentMethod) &&
              tokenMint == null)) {
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
        amount: amount!,
        asset: asset,
        network: chainId == null ? network : '$network (Chain ID $chainId)',
        tokenMint: tokenMint,
        solPriceUsd: order['sol_price_usd']?.toString(),
        paymentMethod: paymentMethod,
        expiresInMinutes: expiresInMinutes,
        isTestnet: isTestnet,
        recipient: recipient,
        assetType: assetType,
      );
    } catch (error) {
      if (mounted) {
        await _loadOpenCryptoOrders();
      }
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

  void _resumeCryptoOrder(
    AuthController authController,
    Map<String, dynamic> order,
  ) {
    final checkoutId = order['checkout_id']?.toString();
    final paymentMethod = order['payment_method']?.toString();
    final amount = order['amount']?.toString();
    final asset = order['asset']?.toString();
    final network = order['network']?.toString();
    final recipient = order['recipient_address']?.toString();
    final tokenMint = order['token_mint']?.toString();
    final assetType = order['asset_type']?.toString();
    final coins = int.tryParse(order['coins']?.toString() ?? '');
    final chainId = int.tryParse(order['chain_id']?.toString() ?? '');
    final decimals =
        int.tryParse(order['decimals']?.toString() ?? '') ??
        (asset == 'SOL' ? 9 : 6);
    final validDecimals = decimals >= 0 && decimals <= 18;
    final amountDecimals = validDecimals ? decimals : 0;
    final isSolana =
        paymentMethod?.startsWith('solana_') == true ||
        assetType?.startsWith('solana_') == true;
    final orderMarkedExpired = order['is_expired'] == true;
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
    final isValidSolana =
        isSolana &&
        ((paymentMethod == 'solana_sol' && asset == 'SOL') ||
            (paymentMethod == 'solana_usdc' && asset == 'USDC') ||
            (paymentMethod == 'solana_usdt' && asset == 'USDT')) &&
        (network == 'Solana Mainnet' ||
            network == 'Solana Devnet (testnet)') &&
        (asset == 'SOL'
            ? tokenMint == null || tokenMint.isEmpty
            : tokenMint != null &&
                RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$').hasMatch(tokenMint));
    final validConfiguredMethod =
        paymentMethod?.startsWith('cfg_') == true &&
        RegExp(r'^[A-Z0-9]{2,20}$').hasMatch(asset ?? '') &&
        [
          'evm_native',
          'evm_token',
          'solana_native',
          'solana_spl',
        ].contains(assetType) &&
        validDecimals &&
        (assetType?.startsWith('evm_') == true
            ? chainId != null && chainId > 0
            : chainId == null) &&
        (assetType == 'evm_token' || assetType == 'solana_spl'
            ? tokenMint != null &&
                (assetType == 'evm_token'
                    ? RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(tokenMint)
                    : RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$')
                        .hasMatch(tokenMint))
            : tokenMint == null);
    final amountPattern = amountDecimals == 0
        ? RegExp(r'^\d+$')
        : RegExp('^\\d+\\.\\d{1,$amountDecimals}\$');
    final validAmount =
        validDecimals && amount != null && amountPattern.hasMatch(amount);
    final validRecipient =
        recipient != null &&
        (isSolana
            ? RegExp(r'^[1-9A-HJ-NP-Za-km-z]{32,44}$').hasMatch(recipient)
            : RegExp(r'^0x[a-fA-F0-9]{40}$').hasMatch(recipient));

    if (checkoutId == null ||
        coins == null ||
        asset == null ||
        network == null ||
        !validAmount ||
        !validRecipient ||
        !(isPolygonMainnet ||
            isPolygonAmoy ||
            isValidSolana ||
            validConfiguredMethod)) {
      Get.snackbar(
        'Unable to reopen checkout',
        'The saved checkout details are invalid. Do not send funds to this order.',
        snackPosition: SnackPosition.BOTTOM,
      );
      return;
    }

    final expiresInMinutes =
        int.tryParse(order['expires_in_minutes']?.toString() ?? '') ?? 0;
    final expiresAtEpoch = int.tryParse(
      order['expires_at_epoch']?.toString() ?? '',
    );
    final currentEpoch = DateTime.now().millisecondsSinceEpoch ~/ 1000;
    final isExpired =
        orderMarkedExpired ||
        (expiresAtEpoch != null && expiresAtEpoch <= currentEpoch);
    _showCryptoPaymentDialog(
      authController,
      checkoutId: checkoutId,
      coins: coins,
      amount: amount!,
      asset: asset!,
      network: chainId == null ? network! : '$network (Chain ID $chainId)',
      tokenMint: tokenMint == null || tokenMint.isEmpty ? null : tokenMint,
      solPriceUsd: order['sol_price_usd']?.toString(),
      paymentMethod: paymentMethod!,
      expiresInMinutes: expiresInMinutes,
      isTestnet:
          order['is_testnet'] == true ||
          network!.contains('testnet') ||
          network == 'Polygon PoS Amoy (testnet)',
      isExpired: isExpired,
      expiresAtEpoch: expiresAtEpoch,
      isResumed: true,
      recipient: recipient,
      assetType: assetType,
    );
  }

  bool _isRetryableCryptoVerificationError(Object error) {
    final message = error.toString().toLowerCase();
    return message.contains('temporarily unavailable') ||
        message.contains('could not connect') ||
        message.contains('connection timeout') ||
        message.contains('no internet connection');
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
    bool isExpired = false,
    int? expiresAtEpoch,
    bool isResumed = false,
    String? tokenMint,
    String? solPriceUsd,
    String? assetType,
  }) {
    final isSolana = _isSolanaMethod(paymentMethod, assetType: assetType);
    final transactionHashController = TextEditingController();
    Timer? expiryTimer;
    var isVerifying = false;
    var remainingMinutes = expiresInMinutes;
    var orderHasExpired =
        isExpired ||
        (expiresAtEpoch != null &&
            expiresAtEpoch <= DateTime.now().millisecondsSinceEpoch ~/ 1000);
    var paymentCompleted = false;
    var verificationMessageIsError = false;
    String? verificationMessage;
    Get.dialog(
      StatefulBuilder(
        builder: (dialogContext, setDialogState) {
          if (expiresAtEpoch != null &&
              expiryTimer == null &&
              !orderHasExpired) {
            expiryTimer = Timer.periodic(const Duration(seconds: 1), (timer) {
              final secondsRemaining =
                  expiresAtEpoch - DateTime.now().millisecondsSinceEpoch ~/ 1000;
              final nextMinutes = secondsRemaining <= 0
                  ? 0
                  : (secondsRemaining + 59) ~/ 60;
              if (dialogContext.mounted &&
                  (secondsRemaining <= 0 ||
                      nextMinutes != remainingMinutes)) {
                setDialogState(() {
                  remainingMinutes = nextMinutes;
                  orderHasExpired = secondsRemaining <= 0;
                });
              }
              if (secondsRemaining <= 0) timer.cancel();
            });
          }
          final isOrderExpired = orderHasExpired;
          final remainingLabel = remainingMinutes >= 60
              ? remainingMinutes % 60 == 0
                    ? '${remainingMinutes ~/ 60} hours'
                    : '${remainingMinutes ~/ 60} hours ${remainingMinutes % 60} minutes'
              : '$remainingMinutes minutes';
          final verificationColor = paymentCompleted
              ? AppColors.success
              : verificationMessageIsError
              ? AppColors.error
              : AppColors.primary;
          final verificationIcon = paymentCompleted
              ? Icons.check_circle_rounded
              : verificationMessageIsError
              ? Icons.error_outline_rounded
              : Icons.hourglass_top_rounded;
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
                                isOrderExpired
                                    ? 'Order amount: $amount $asset'
                                    : 'Send exactly $amount $asset',
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
                          onPressed: isOrderExpired
                              ? null
                              : () async {
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
                                isOrderExpired
                                    ? 'Expired · verify only if already paid before expiry'
                                    : 'Valid for $remainingLabel · exact amount required',
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
                  if (isResumed && !isOrderExpired) ...[
                    const SizedBox(height: 10),
                    Container(
                      padding: const EdgeInsets.all(11),
                      decoration: BoxDecoration(
                        color: AppColors.primary.withValues(alpha: 0.06),
                        borderRadius: BorderRadius.circular(13),
                      ),
                      child: const Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Icon(
                            Icons.info_outline_rounded,
                            color: AppColors.primary,
                            size: 18,
                          ),
                          SizedBox(width: 8),
                          Expanded(
                            child: Text(
                              'This is an existing checkout. If you already sent the payment, do not send it again; enter the original transaction signature below.',
                              style: TextStyle(
                                color: AppColors.textSecondary,
                                fontSize: 11,
                                height: 1.35,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
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
                        onPressed: isOrderExpired
                            ? null
                            : () async {
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
                          onPressed: isOrderExpired
                              ? null
                              : () async {
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
                      color: (isOrderExpired || isTestnet
                              ? AppColors.error
                              : AppColors.accent)
                          .withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(14),
                      border: Border.all(
                        color: (isOrderExpired || isTestnet
                                ? AppColors.error
                                : AppColors.accent)
                            .withValues(alpha: 0.2),
                      ),
                    ),
                    child: Row(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Icon(
                          isTestnet || isOrderExpired
                              ? Icons.warning_amber_rounded
                              : Icons.info_outline_rounded,
                          size: 18,
                          color: isTestnet || isOrderExpired
                              ? AppColors.error
                              : AppColors.primary,
                        ),
                        const SizedBox(width: 9),
                        Expanded(
                          child: Text(
                            isOrderExpired
                                ? 'ORDER EXPIRED: Do not send funds now. If you already paid before expiry, paste that transaction signature to verify it.'
                                : isTestnet
                                ? 'TESTNET ONLY: Test tokens have no real value. Never send mainnet funds.'
                                : isSolana
                                ? 'Send only $asset on this network. SOL is also needed for network fees. Transfers are usually irreversible.'
                                : 'Send only $asset on this network. POL is also needed for network fees. Transfers are usually irreversible.',
                            style: TextStyle(
                              fontSize: 11,
                              height: 1.35,
                              color: isTestnet || isOrderExpired
                                  ? AppColors.error
                                  : AppColors.textSecondary,
                              fontWeight: isTestnet || isOrderExpired
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
                    enabled: !isVerifying && !paymentCompleted,
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
                  Text(
                    isOrderExpired
                        ? 'Only transactions sent before this order expired can be verified and credited.'
                        : 'After sending, paste the transaction signature here. Coins are added only after the server verifies the finalized transfer.',
                    style: const TextStyle(
                      fontSize: 12,
                      color: AppColors.textSecondary,
                    ),
                  ),
                  if (verificationMessage != null) ...[
                    const SizedBox(height: 12),
                    Container(
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: verificationColor.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(14),
                        border: Border.all(
                          color: verificationColor.withValues(alpha: 0.25),
                        ),
                      ),
                      child: Row(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: [
                          Icon(
                            verificationIcon,
                            size: 19,
                            color: verificationColor,
                          ),
                          const SizedBox(width: 9),
                          Expanded(
                            child: Text(
                              verificationMessage!,
                              style: TextStyle(
                                fontSize: 12,
                                height: 1.4,
                                color: verificationColor,
                                fontWeight: paymentCompleted
                                    ? FontWeight.w700
                                    : FontWeight.normal,
                              ),
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
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
                        if (paymentCompleted) {
                          Get.back();
                          return;
                        }
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
                          setDialogState(() {
                            verificationMessageIsError = true;
                            verificationMessage =
                                'Enter the full transaction signature from your wallet.';
                          });
                          return;
                        }
                        setDialogState(() {
                          isVerifying = true;
                          verificationMessage = null;
                          verificationMessageIsError = false;
                        });
                        try {
                          late Map<String, dynamic> result;
                          var automaticRetries = 0;
                          final maxAutomaticRetries = isSolana ? 6 : 12;
                          final retryDelay = Duration(
                            seconds: isSolana ? 5 : 10,
                          );

                          while (true) {
                            try {
                              result = await authController.verifyCryptoPayment(
                                checkoutId,
                                transactionHash,
                                isSolana: isSolana,
                                isConfiguredCrypto: paymentMethod.startsWith(
                                  'cfg_',
                                ),
                              );
                            } catch (error) {
                              if (!_isRetryableCryptoVerificationError(error) ||
                                  automaticRetries >= maxAutomaticRetries) {
                                rethrow;
                              }
                              if (!dialogContext.mounted) return;
                              automaticRetries++;
                              setDialogState(() {
                                verificationMessageIsError = false;
                                verificationMessage =
                                    'The network is temporarily unavailable. '
                                    'Retrying verification '
                                    '($automaticRetries/$maxAutomaticRetries). '
                                    'Do not send the payment again.';
                              });
                              await Future.delayed(retryDelay);
                              if (!dialogContext.mounted) return;
                              continue;
                            }

                            if (!dialogContext.mounted) return;
                            final status = result['status']?.toString();
                            if (status != 'pending' && status != 'confirming') {
                              break;
                            }
                            if (automaticRetries >= maxAutomaticRetries) {
                              break;
                            }
                            automaticRetries++;
                            setDialogState(() {
                              verificationMessageIsError = false;
                              final statusMessage =
                                  result['message']?.toString() ??
                                  'Payment is still confirming.';
                              verificationMessage =
                                  '$statusMessage Automatically checking again '
                                  '($automaticRetries/$maxAutomaticRetries). '
                                  'Do not send the payment again.';
                            });
                            await Future.delayed(retryDelay);
                            if (!dialogContext.mounted) return;
                          }

                          if (!dialogContext.mounted) return;
                          final status = result['status']?.toString();
                          if (status == 'completed') {
                            final balance = result['balance']?.toString();
                            final alreadyProcessed =
                                result['already_processed'] == true;
                            setDialogState(() {
                              isVerifying = false;
                              paymentCompleted = true;
                              verificationMessageIsError = false;
                              verificationMessage =
                                  result['balance_cache_warning'] == true
                                  ? 'Payment verified and credited. The balance is $balance coins, but the app could not save it locally. Reopen the wallet to refresh.'
                                  : alreadyProcessed
                                  ? 'This payment was already processed. Your balance is $balance coins.'
                                  : 'Payment verified! $coins coins were added. Your balance is now $balance.';
                            });
                          } else if (status == 'pending' ||
                              status == 'confirming') {
                            final confirmations =
                                result['confirmations']?.toString();
                            final requiredConfirmations =
                                result['required_confirmations']?.toString();
                            setDialogState(() {
                              isVerifying = false;
                              verificationMessageIsError = false;
                              verificationMessage =
                                  result['message']?.toString() ??
                                  (confirmations != null &&
                                          requiredConfirmations != null
                                      ? 'Payment is still confirming ($confirmations/$requiredConfirmations). Keep this signature and try again shortly.'
                                      : 'The network has not finalized this transaction yet. Keep this signature and try again shortly.');
                              if (automaticRetries >= maxAutomaticRetries) {
                                verificationMessage =
                                    '$verificationMessage Automatic checks '
                                    'have paused; you can verify again shortly '
                                    'with the same signature.';
                              }
                            });
                          } else {
                            setDialogState(() {
                              isVerifying = false;
                              verificationMessageIsError = true;
                              verificationMessage =
                                  'The server returned an unexpected payment status. You can retry verification with this signature.';
                            });
                          }
                        } catch (error) {
                          debugPrint(
                            'Crypto payment verification UI failed: $error',
                          );
                          if (dialogContext.mounted) {
                            setDialogState(() {
                              verificationMessageIsError = true;
                              final message = error
                                  .toString()
                                  .replaceFirst('Bad state: ', '');
                              verificationMessage =
                                  '$message\nIf you retry, use the same signature; do not send the payment again.';
                            });
                          }
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
                    : Row(
                        mainAxisSize: MainAxisSize.min,
                        children: [
                          const Icon(Icons.verified_rounded, size: 18),
                          const SizedBox(width: 7),
                          Text(
                            paymentCompleted ? 'Done' : 'Verify payment',
                            style: const TextStyle(
                              fontWeight: FontWeight.bold,
                            ),
                          ),
                        ],
                      ),
              ),
            ],
          );
        },
      ),
      barrierDismissible: false,
    ).whenComplete(() {
      expiryTimer?.cancel();
      transactionHashController.dispose();
      if (mounted) _loadOpenCryptoOrders();
    });
  }
}
