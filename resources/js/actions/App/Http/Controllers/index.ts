import CityController from './CityController'
import BranchController from './BranchController'
import ServiceTemplateController from './ServiceTemplateController'
import PaymentMethodController from './PaymentMethodController'
import CustomerController from './CustomerController'
import AppSettingController from './AppSettingController'
import BranchServiceController from './BranchServiceController'
import ProductCategoryController from './ProductCategoryController'
import CouponController from './CouponController'
import ProductController from './ProductController'
import Settings from './Settings'
import Auth from './Auth'
const Controllers = {
    CityController: Object.assign(CityController, CityController),
BranchController: Object.assign(BranchController, BranchController),
ServiceTemplateController: Object.assign(ServiceTemplateController, ServiceTemplateController),
PaymentMethodController: Object.assign(PaymentMethodController, PaymentMethodController),
CustomerController: Object.assign(CustomerController, CustomerController),
AppSettingController: Object.assign(AppSettingController, AppSettingController),
BranchServiceController: Object.assign(BranchServiceController, BranchServiceController),
ProductCategoryController: Object.assign(ProductCategoryController, ProductCategoryController),
CouponController: Object.assign(CouponController, CouponController),
ProductController: Object.assign(ProductController, ProductController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers