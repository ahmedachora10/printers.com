import CityController from './CityController'
import BranchController from './BranchController'
import ServiceTemplateController from './ServiceTemplateController'
import BranchServiceController from './BranchServiceController'
import ProductCategoryController from './ProductCategoryController'
import CouponController from './CouponController'
import Settings from './Settings'
import Auth from './Auth'
const Controllers = {
    CityController: Object.assign(CityController, CityController),
BranchController: Object.assign(BranchController, BranchController),
ServiceTemplateController: Object.assign(ServiceTemplateController, ServiceTemplateController),
BranchServiceController: Object.assign(BranchServiceController, BranchServiceController),
ProductCategoryController: Object.assign(ProductCategoryController, ProductCategoryController),
CouponController: Object.assign(CouponController, CouponController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers