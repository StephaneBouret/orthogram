import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    submit(event) {
        if (!event.target.files?.length) {
            return;
        }

        this.element.requestSubmit();
    }
}
