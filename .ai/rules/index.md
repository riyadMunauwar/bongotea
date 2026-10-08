# Project Rules Index

Before planning or editing, find the row whose globs match the file's path and read that rule file.

| Applies to | Rule file |
| --- | --- |
| app/Enums/Permission.php, app/Models/Role.php, app/Models/User.php, app/Support/Access/**, app/Http/Middleware/AuthenticateAdmin.php, app/Console/Commands/SyncPermissionsCommand.php, app/Console/Commands/GrantOwnerCommand.php, database/factories/UserFactory.php, app/Filament/Resources/Users/**, app/Filament/Pages/Auth/EditProfile.php | .ai/rules/access.md |
| resources/css/filament/admin/** | .ai/rules/admin.md |
| app/Models/Product.php, app/Models/Category.php, app/Models/Brand.php, app/Models/Tag.php, app/Enums/CategoryTheme.php, app/Enums/ProductStatus.php, app/Filament/Resources/Products/**, app/Filament/Resources/Categories/**, app/Filament/Resources/Brands/**, app/Filament/Resources/Tags/** | .ai/rules/catalogue.md |
| app/Filament/Concerns/** | .ai/rules/concerns.md |
| app/Support/Couriers/**, app/Enums/Courier.php, app/Enums/ShipmentStatus.php, app/Models/Shipment.php, app/Models/ShipmentEvent.php, app/Filament/Resources/Orders/Actions/BookWithCourierAction.php, tests/Fixtures/Couriers/**, app/Console/Commands/SweepCouriersCommand.php | .ai/rules/couriers.md |
| app/Filament/** | .ai/rules/filament.md |
| app/Support/Inventory/**, app/Models/StockMovement.php, app/Models/StockCount.php, app/Models/Supplier.php, app/Models/Supply.php, app/Models/SupplyLine.php, app/Enums/StockMovementReason.php, app/Enums/AdjustmentCause.php, app/Support/Orders/OrderCapture.php, app/Models/OrderLine.php, app/Filament/Clusters/Inventory/** | .ai/rules/inventory.md |
| resources/js/** | .ai/rules/js.md |
| app/Support/Orders/OrderOwnership.php, app/Http/Controllers/CheckoutController.php, resources/views/checkout/order-complete.blade.php | .ai/rules/order-complete.md |
| app/Filament/Resources/Orders/** | .ai/rules/orders.md |
| app/Support/Payments/**, app/Models/Payment.php, app/Http/Controllers/PaymentController.php, resources/views/checkout/processing.blade.php, tests/Fixtures/Payments/** | .ai/rules/payments.md |
| app/Filament/Resources/** | .ai/rules/resources.md |
| routes/*.php, app/Http/Controllers/TrackingController.php, app/Http/Controllers/CourierController.php | .ai/rules/routes.md |
| app/Support/Settings/**, app/Models/Setting.php, app/Filament/Clusters/Settings/**, app/Providers/Filament/AdminPanelProvider.php, app/Support/Reporting/** | .ai/rules/settings.md |
| app/Filament/Resources/Shipments/** | .ai/rules/shipments.md |
| app/Support/Sms/**, app/Enums/SmsGateway.php, app/Enums/OrderText.php, app/Support/Settings/SmsSettings.php, app/Observers/OrderTextObserver.php, app/Observers/ShipmentTextObserver.php, app/Filament/Clusters/Settings/Pages/Sms.php, tests/Fixtures/Sms/** | .ai/rules/sms.md |
| config/filesystems.php, config/livewire.php, app/Models/Product.php, app/Filament/Resources/Products/** | .ai/rules/storage.md |
| app/Filament/Clusters/Inventory/Resources/Supplies/**, app/Filament/Clusters/Inventory/Resources/Suppliers/** | .ai/rules/supplies.md |
| tests/** | .ai/rules/tests.md |
| app/Support/Tracking/**, app/Models/Destination.php, app/Models/TrackingEvent.php, app/Models/TrackingContext.php, app/Observers/DestinationObserver.php, app/Enums/Tracking*.php, app/Console/Commands/*Tracking*.php, app/Filament/Resources/Destinations/**, tests/Fixtures/Tracking/** | .ai/rules/tracking.md |
| app/Filament/Widgets/**, app/Filament/Resources/**/Widgets/**, app/Filament/Clusters/Reports/** | .ai/rules/widgets.md |
