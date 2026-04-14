import { startStimulusApp } from '@symfony/stimulus-bundle';
import AvatarUploadController    from './controllers/avatar_upload_controller.js';
import BulkSelectController      from './controllers/bulk_select_controller.js';
import FriendshipController      from './controllers/friendship_controller.js';
import UserSearchController      from './controllers/user_search_controller.js';

const app = startStimulusApp();
app.register('avatar-upload', AvatarUploadController);
app.register('bulk-select',   BulkSelectController);
app.register('friendship',    FriendshipController);
app.register('user-search',   UserSearchController);
