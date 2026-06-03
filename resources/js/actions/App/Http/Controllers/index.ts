import CityController from './CityController'
import BranchController from './BranchController'
import ServiceTemplateController from './ServiceTemplateController'
import PaymentMethodController from './PaymentMethodController'
import ProductInvoiceController from './ProductInvoiceController'
import StockMovementController from './StockMovementController'
import RefundController from './RefundController'
import ServiceInvoiceController from './ServiceInvoiceController'
import CustomerController from './CustomerController'
import InvoiceController from './InvoiceController'
import UserController from './UserController'
import AppSettingController from './AppSettingController'
import BranchServiceController from './BranchServiceController'
import ProductCategoryController from './ProductCategoryController'
import CouponController from './CouponController'
import CommissionController from './CommissionController'
import ProductController from './ProductController'
import Settings from './Settings'
import Auth from './Auth'
const Controllers = {
    CityController: Object.assign(CityController, CityController),
BranchController: Object.assign(BranchController, BranchController),
ServiceTemplateController: Object.assign(ServiceTemplateController, ServiceTemplateController),
PaymentMethodController: Object.assign(PaymentMethodController, PaymentMethodController),
ProductInvoiceController: Object.assign(ProductInvoiceController, ProductInvoiceController),
StockMovementController: Object.assign(StockMovementController, StockMovementController),
RefundController: Object.assign(RefundController, RefundController),
ServiceInvoiceController: Object.assign(ServiceInvoiceController, ServiceInvoiceController),
CustomerController: Object.assign(CustomerController, CustomerController),
InvoiceController: Object.assign(InvoiceController, InvoiceController),
UserController: Object.assign(UserController, UserController),
AppSettingController: Object.assign(AppSettingController, AppSettingController),
BranchServiceController: Object.assign(BranchServiceController, BranchServiceController),
ProductCategoryController: Object.assign(ProductCategoryController, ProductCategoryController),
CouponController: Object.assign(CouponController, CouponController),
CommissionController: Object.assign(CommissionController, CommissionController),
ProductController: Object.assign(ProductController, ProductController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers