import CityController from './CityController'
import BranchController from './BranchController'
import ServiceTemplateController from './ServiceTemplateController'
import CustomerController from './CustomerController'
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
CustomerController: Object.assign(CustomerController, CustomerController),
BranchServiceController: Object.assign(BranchServiceController, BranchServiceController),
ProductCategoryController: Object.assign(ProductCategoryController, ProductCategoryController),
CouponController: Object.assign(CouponController, CouponController),
ProductController: Object.assign(ProductController, ProductController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers