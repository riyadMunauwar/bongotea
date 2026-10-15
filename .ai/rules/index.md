# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Enums/Permission.php, app/Models/Role.php, app/Models/User.php, app/Support/Access/**, app/Http/Middleware/AuthenticateAdmin.php, app/Console/Commands/SyncPermissionsCommand.php, app/Console/Commands/GrantOwnerCommand.php, database/factories/UserFactory.php, app/Filament/Resources/Users/**, app/Filament/Pages/Auth/EditProfile.php | .ai/rules/access.md |
| resources/css/filament/admin/** | .ai/rules/admin.md |
| app/** | .ai/rules/app.md |
| app/Models/Product.php, app/Models/Pack.php, app/Models/Category.php, app/Models/Brand.php, app/Models/Tag.php, app/Enums/CategoryTheme.php, app/Enums/ProductStatus.php, app/Filament/Resources/Products/**, app/Filament/Resources/Categories/**, app/Filament/Resources/Brands/**, app/Filament/Resources/Tags/**, app/Models/Cart.php, app/Models/CartProduct.php, app/Filament/Resources/Carts/** | .ai/rules/catalogue.md |
| app/Filament/Concerns/** | .ai/rules/concerns.md |
| app/Support/Couriers/**, app/Enums/Courier.php, app/Enums/ShipmentStatus.php, app/Models/Shipment.php, app/Models/ShipmentEvent.php, app/Filament/Resources/Orders/Actions/BookWithCourierAction.php, tests/Fixtures/Couriers/**, app/Console/Commands/SweepCouriersCommand.php | .ai/rules/couriers.md |
| app/Support/Customers/**, app/Models/Customer.php, app/Models/OneTimeCode.php, app/Enums/OrderProgress.php, app/Http/Controllers/SigningInController.php, app/Http/Requests/OneTimeCodeRequest.php, app/Http/Requests/SignInRequest.php, app/Support/Pages/Blocks/MyOrders.php | .ai/rules/customers.md |
| {Dockerfile,docker-compose.yml,deploy/docker/**,config/trustedproxy.php} | .ai/rules/docker.md |
| app/Models/Enquiry.php, app/Enums/EnquiryStatus.php, app/Http/Controllers/EnquiryController.php, app/Http/Requests/EnquiryRequest.php, app/Observers/EnquiryAlertObserver.php, app/Support/Sms/EnquiryAlerts.php, app/Support/Pages/Blocks/ContactForm.php, app/Filament/Resources/Enquiries/** | .ai/rules/enquiries.md |
| app/Filament/** | .ai/rules/filament.md |
| app/Support/Inventory/**, app/Models/StockMovement.php, app/Models/StockCount.php, app/Models/Supplier.php, app/Models/Supply.php, app/Models/SupplyLine.php, app/Enums/StockMovementReason.php, app/Enums/AdjustmentCause.php, app/Support/Orders/OrderCapture.php, app/Models/OrderLine.php, app/Filament/Clusters/Inventory/**, app/Support/Inventory/Grams.php, app/Support/Orders/** | .ai/rules/inventory.md |
| resources/js/** | .ai/rules/js.md |
| resources/js/lib/cart.ts, resources/js/lib/contents.ts, resources/js/lib/use-adding.ts, resources/js/lib/current-cart.ts | .ai/rules/lib.md |
| app/Support/Menus/**, app/Models/Menu.php, app/Models/MenuItem.php, app/Filament/Resources/Menus/**, app/Filament/Pages/Header.php, app/Filament/Pages/Footer.php, app/Support/Settings/HeaderSettings.php, app/Support/Settings/FooterSettings.php, app/Support/Storefront/StorefrontMenus.php | .ai/rules/menus.md |
| app/Support/Orders/OrderOwnership.php, app/Http/Controllers/CheckoutController.php, resources/views/checkout/order-complete.blade.php | .ai/rules/order-complete.md |
| app/Filament/Resources/Orders/** | .ai/rules/orders.md |
| app/Support/Pages/**, app/Models/Page.php, app/Http/Controllers/PageController.php, app/Filament/Resources/Pages/**, resources/js/components/blocks/**, resources/js/types/blocks.ts, app/Support/Pages/CartPlacements.php | .ai/rules/pages.md |
| app/Support/Payments/**, app/Models/Payment.php, app/Http/Controllers/PaymentController.php, resources/views/checkout/processing.blade.php, tests/Fixtures/Payments/** | .ai/rules/payments.md |
| app/Filament/Resources/** | .ai/rules/resources.md |
| routes/*.php, app/Http/Controllers/TrackingController.php, app/Http/Controllers/CourierController.php | .ai/rules/routes.md |
| app/Support/Settings/**, app/Models/Setting.php, app/Filament/Clusters/Settings/**, app/Providers/Filament/AdminPanelProvider.php, app/Support/Reporting/** | .ai/rules/settings.md |
| app/Filament/Resources/Shipments/** | .ai/rules/shipments.md |
| app/Support/Sms/**, app/Enums/SmsGateway.php, app/Enums/OrderText.php, app/Support/Settings/SmsSettings.php, app/Observers/OrderTextObserver.php, app/Observers/ShipmentTextObserver.php, app/Filament/Clusters/Settings/Pages/Sms.php, tests/Fixtures/Sms/** | .ai/rules/sms.md |
| config/filesystems.php, config/livewire.php, app/Models/Product.php, app/Filament/Resources/Products/** | .ai/rules/storage.md |
| app/Support/Storefront/** | .ai/rules/storefront.md |
| app/Filament/Clusters/Inventory/Resources/Supplies/**, app/Filament/Clusters/Inventory/Resources/Suppliers/** | .ai/rules/supplies.md |
| tests/** | .ai/rules/tests.md |
| app/Support/Tracking/**, app/Models/TrackingEvent.php, app/Models/TrackingContext.php, app/Enums/Tracking*.php, app/Console/Commands/*Tracking*.php, app/Filament/Clusters/Settings/Pages/Tracking.php, app/Filament/Clusters/Settings/Widgets/**, tests/Fixtures/Tracking/** | .ai/rules/tracking.md |
| app/Filament/Widgets/**, app/Filament/Resources/**/Widgets/**, app/Filament/Clusters/Reports/**, app/Filament/Clusters/Settings/Widgets/** | .ai/rules/widgets.md |
