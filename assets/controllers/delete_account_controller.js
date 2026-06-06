import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["input", "button"];

    connect() {
        this.check();
    }

    check() {
        const confirmed = this.inputTarget.value.trim().toUpperCase() === "SUPPRIMER";
        this.buttonTarget.disabled = !confirmed;
    }
}
