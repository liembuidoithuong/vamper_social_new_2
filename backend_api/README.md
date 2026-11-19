# Party room media configuration

Party voice and screen sharing use LiveKit. Configure these variables in the
PHP-FPM/Apache environment on the API server; do not put the API secret in
Flutter, the SQL dump, or a public `.env` file:

```text
LIVEKIT_URL=wss://your-project.livekit.cloud
LIVEKIT_API_KEY=your-livekit-api-key
LIVEKIT_API_SECRET=your-livekit-api-secret
```

Pass the variables through the PHP-FPM pool if its environment is cleared, then
restart PHP-FPM or Apache. If the server does not provide all three values,
chat, seats, reactions, and presence still work, but
the app will report that voice and screen sharing are unavailable. The backend
issues short-lived room tokens. Microphone publishing is limited to speaker
seats; screen sharing is limited to the host or a seated participant explicitly
approved by the host. A host or co-host can lock a participant's microphone.

## Admin portal

The PHP admin portal and API use the `VAMPER_DB_HOST`, `VAMPER_DB_NAME`,
`VAMPER_DB_USER`, and `VAMPER_DB_PASSWORD` environment variables when present.
The existing local defaults remain `localhost` / `vamper_social` / `root` /
empty password. Set production credentials in the PHP-FPM/Apache environment,
not in source control.

On the first admin login, additive migrations create `admin_accounts`,
`admin_audit_log`, and `vpc_ledger`, add report-review metadata and the
`shop_items.is_active` flag, and preserve existing accounts and purchases. The
PayPal checkout endpoints create their own order and idempotency tables on
first use.
Existing `users.role = 'admin'` accounts are initialized as `super_admin` so
they retain access. The database account needs `CREATE` and `ALTER` privileges
for this one-time initialization. No database reset or full SQL import is
required. Existing sessions are invalidated by the admin-only session cookie.

The **Quản Lý Phòng Party** tab lists live and closed rooms with search and
pagination. Super admins and moderators can create rooms, edit room details,
open or close rooms, and permanently delete a room and its associated chat and
presence data. Admin-created rooms use the signed-in admin account as host.
When LiveKit is configured, closing or deleting a live room also asks LiveKit
to terminate its media room.

Audio monitoring is listen-only, restricted to super admins, and available only
for live rooms. It uses a short-lived LiveKit token without microphone or data
publishing permission and does not record audio. While monitoring is active,
Party clients display a visible banner naming the admin. The monitor session
is also written to the admin audit log; its visible room status expires within
30 seconds if the admin connection ends unexpectedly.
The admin browser loads the pinned LiveKit JavaScript SDK (`2.24.1`) from
jsDelivr for playback, so that host must be reachable from the admin browser.

Admin sessions use password hashes only, CSRF tokens, role permissions, and
audited write actions. User and post moderation is reversible; user deletion
has been removed. VPC adjustments, shop purchases, gifts, and sandbox top-ups
are recorded in the additive VPC ledger. Older coin activity is intentionally
not backfilled because trustworthy before/after balances are unavailable.

The Wallet & Transactions admin tab manages Polygon USDC and Solana SOL
payment profiles. It stores a separate enabled flag, HTTPS RPC URL, and
receiving address for each supported testnet/mainnet profile in the existing
`settings` table. Saving a profile selects that network for new checkouts;
existing pending orders continue to verify against their recorded network and
recipient. Database values take precedence over the legacy payment environment
variables. RPC URLs are visible to finance admins, so do not put provider
secrets in them or share screenshots containing credentials.

PayPal checkout is disabled in the API and its admin configuration has been
removed. Existing PayPal order verification remains available for checkouts
created before it was disabled.

Legacy PayPal credentials, if present, remain server-side only. Never put
PayPal client secrets in Flutter, SQL, or source control:

```text
PAYPAL_SANDBOX_CLIENT_ID=your-sandbox-client-id
PAYPAL_SANDBOX_CLIENT_SECRET=your-sandbox-client-secret
PAYPAL_LIVE_CLIENT_ID=your-live-client-id
PAYPAL_LIVE_CLIENT_SECRET=your-live-client-secret
PAYPAL_RETURN_URL=https://api.example.com/api/paypal-return.php
```

PayPal credentials are retained above for reference, but the create-order API
returns unavailable while the PayPal checkout kill switch is off.

## Polygon USDC coin top-ups

The app supports native Circle USDC on Polygon PoS (chain ID `137`), not
bridged `USDC.e`. Set these variables in the production PHP-FPM/Apache
environment until an admin profile is saved; after that, the admin profile
overrides them. Never put an RPC provider key or wallet seed/private key in
the app or source control:

```text
POLYGON_USDC_ENABLED=0
POLYGON_USDC_NETWORK=mainnet
POLYGON_RPC_URL=https://your-polygon-rpc-provider.example
POLYGON_USDC_RECEIVER_ADDRESS=0xYourPublicReceivingAddress
POLYGON_USDC_MIN_CONFIRMATIONS=64
```

Use an HTTPS RPC endpoint and a dedicated receiving address controlled by the
project owner. The receiving address is public; its private key must remain
offline or in a properly secured wallet. The server does not need the wallet's
private key. The API only enables checkouts when cURL, a valid HTTPS RPC URL,
a valid non-zero receiving address, and a confirmation threshold of at least
64 are configured and the profile is enabled. Network-specific settings are
managed in the admin portal.

Each checkout is valid for 24 hours and receives a unique exact USDC amount
whose fractional difference from the package price is less than `0.01 USDC`.
The app displays the exact amount and recipient. Users must send native USDC
over Polygon PoS and pay the network fee in POL. The API checks the chain,
official USDC contract, transfer recipient, exact amount, receipt status,
checkout time window, and confirmations; it credits the wallet and ledger in
one database transaction and prevents reuse of a transaction hash. Do not
credit from screenshots or manually supplied amounts.

### Test safely on Amoy

Use a separate staging backend and staging database. Never enable Amoy
test-token checkout on the production database, because successful test
payments add test VPC to the connected database.

```text
POLYGON_USDC_ENABLED=1
POLYGON_USDC_NETWORK=amoy
POLYGON_RPC_URL=https://polygon-amoy.drpc.org
POLYGON_USDC_RECEIVER_ADDRESS=0xYourStagingWalletAddress
POLYGON_USDC_MIN_CONFIRMATIONS=64
```

The app identifies Amoy as a testnet and warns users not to send mainnet funds.
Get test POL from the [Polygon faucet](https://faucet.polygon.technology/) for
gas, and test USDC on Polygon PoS Amoy from the
[Circle faucet](https://faucet.circle.com/). Circle's Amoy USDC contract is
`0x41E94Eb019C0762f9Bfcf9Fb1E58725BfB0e7582`; mainnet uses a different token
contract. Test-token top-ups are recorded as `sandbox_mock`.

After testing the create-order, exact-amount, transaction-hash, confirmation,
duplicate-submit, and balance/ledger flows on staging, configure a separate
mainnet receiver/RPC and only then explicitly enable mainnet. Blockchain
transfers are generally irreversible; incorrect network, token, address, or
amount may be unrecoverable. Review applicable legal, tax, and app store
requirements before accepting payments.

## Solana coin top-ups

New Solana top-ups currently support native SOL only. Configure the selected
Solana network, HTTPS RPC URL, and public receiving address in the admin Wallet
& Transactions tab. Legacy `SOLANA_*` environment variables are used until an
admin profile is saved. The server never needs the wallet's private key.
Checkout stays disabled unless the profile is enabled and its settings are
valid.

```text
SOLANA_ENABLED=0
SOLANA_NETWORK=mainnet-beta
SOLANA_RPC_URL=https://api.mainnet-beta.solana.com
SOLANA_RECEIVER_ADDRESS=YourBase58ReceivingAddress
COINGECKO_API_KEY=optional-demo-api-key
```

Native SOL checkout fetches a current SOL/USD quote from CoinGecko, stores the
rate on the order, and locks that amount for 15 minutes. The quote is cached
server-side briefly to respect public API limits. A missing, stale, or invalid
quote fails checkout rather than using a fallback price. The verifier requires
a finalized Solana transaction with the matching recipient, amount, and order
time window before crediting coins.

For safe testing, use a separate staging backend and database:

```text
SOLANA_ENABLED=1
SOLANA_NETWORK=devnet
SOLANA_RPC_URL=https://api.devnet.solana.com
SOLANA_RECEIVER_ADDRESS=YourDevnetReceivingAddress
```

Point a staging app build at the staging API (never the production API) with
`--dart-define=API_BASE_URL=https://staging.example.com/api`. Without this
override, the app continues to use the production API URL.

Solana Devnet currently enables native SOL top-ups only. Obtain test SOL from
the [Solana faucet](https://faucet.solana.com/). Devnet credits are recorded as
`sandbox_mock`. Never enable devnet on a production database.

Party music playback is not implemented yet. The shop exposes profile frames
and avatars only; legacy Party-frame records are hidden and cannot be purchased
or equipped.

## Profile cosmetics shop

Opening the shop through `get-shop-items.php` installs any missing profile
frames and avatars in `shop_items`, including on an existing database. This
catalog update is additive and does not replace existing shop rows or
`user_items` ownership; no database reset or full SQL import is needed. Avatar
artwork is currently served by DiceBear, so those images require internet
access to that service.

Run the Party table additions in `../vamper_social.sql` when applying the schema;
the Party APIs also create the new tables for existing installations. Party
passwords are hashed on creation, and the password is never returned by the
party-list endpoint.

Android screen sharing requests the system capture permission and runs the
required foreground service. On iOS, LiveKit can capture the app itself; sharing
other apps requires configuring a ReplayKit Broadcast Upload Extension and a
shared App Group in the Xcode project.

## Virtual gifts

The authenticated `get-gifts.php` and `send-gift.php` endpoints initialize
the gift catalog, immutable transaction history, and notification storage for
existing installations. The catalog is seeded with starter emoji gifts and
prices in VPC coins. `send-gift.php` handles direct chat gifts; Party gifts use
the `send_gift` action in `party-room.php`. The server validates recipients,
loads prices from the catalog, locks the sender's balance, and records the
coin debit and delivery atomically. Request IDs prevent a retried request from
charging twice. Gift value is not credited to the recipient in this initial
version.

The authenticated `get-my-gifts.php` endpoint builds the recipient's collection
and recent-gift history directly from `gift_transactions`, the authoritative
gift ledger. No additional inventory table or SQL migration is required;
existing gift transactions are included automatically. Received gifts are
collectibles and cannot be redeemed or transferred for VPC coins.

In chat, the sender sees a gift celebration immediately after the gift API
confirms delivery. The recipient sees the matching celebration when message
polling detects the new gift; visual tiers scale with catalog coin value.

## Chat delivery and presence

The authenticated `chat-presence.php` endpoint records app heartbeats and
explicit offline transitions in the additive `user_presence` table, created on
first use. Flutter sends heartbeats while the app is foregrounded and marks the
user offline when it is backgrounded. A user is considered online only if the
last heartbeat is under 45 seconds old and their `settings_show_online_status`
setting is enabled; stale sessions therefore expire without a database reset.

Opening a conversation marks its incoming messages as read before returning
them. The sender's chat polling then receives the updated `is_read` state, which
drives the sent/seen check mark shown on outgoing messages.

## Voice Match calls

Voice Match matchmaking uses the authenticated `voice-match-session.php`
endpoint. It pairs two active queue members with mutually compatible gender
preferences and issues room-scoped LiveKit tokens that can publish microphone
audio only. The Flutter call screen connects to the returned room, requests
microphone access, and uses authenticated heartbeats to detect the other
participant leaving. Voice Match queue/session tables are initialized on first
use; both participants need the LiveKit URL, API key, and API secret configured
for calls to connect.

## One-to-one chat calls

The authenticated `chat-call.php` endpoint supports audio and video calls
between users who have both sent a message in the same conversation. It handles
ringing, accept/decline, heartbeats, and ending calls; LiveKit tokens are issued
only after acceptance. Audio calls can publish microphone audio only, while
video calls can publish microphone audio and camera video. Android and iOS need
microphone and camera permissions enabled for the respective call features.
Incoming calls are currently checked while the recipient has the matching chat
open in the foreground; background notifications and calls from other screens
are not implemented yet.
