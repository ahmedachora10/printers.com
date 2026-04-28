import CityController from './CityController'
import BranchController from './BranchController'
import Settings from './Settings'
import Auth from './Auth'
const Controllers = {
    CityController: Object.assign(CityController, CityController),
BranchController: Object.assign(BranchController, BranchController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers