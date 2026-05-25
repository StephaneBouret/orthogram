import { startStimulusApp } from '@symfony/stimulus-bundle';
import BackToTopController from './controllers/back_to_top_controller.js';
import ThemeController from './controllers/theme_controller.js';
import ProgramFloatingNavController from './controllers/program_floating_nav_controller.js';

const app = startStimulusApp();
// register any custom, 3rd party controllers here
// app.register('some_controller_name', SomeImportedController);
app.register('back-to-top', BackToTopController);
app.register('theme', ThemeController);
app.register('program-floating-nav', ProgramFloatingNavController);
