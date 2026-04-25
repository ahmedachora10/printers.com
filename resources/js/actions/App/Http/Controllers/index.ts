import CityController from './CityController'
import Settings from './Settings'
import Auth from './Auth'
const Controllers = {
    CityController: Object.assign(CityController, CityController),
Settings: Object.assign(Settings, Settings),
Auth: Object.assign(Auth, Auth),
}

export default Controllers