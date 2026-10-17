import { Application } from '@hotwired/stimulus';
import DictationEditor from '../controllers/dictation_editor_controller.js';
import '../styles/admin_dictation.css';

const application = Application.start();
application.register('dictation-editor', DictationEditor);
