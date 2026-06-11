import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static targets = ["input", "alert", "password", "passwordWrapper"];
    static values = {
        initial: String,
    };

    connect() {
        this.toggle = this.toggle.bind(this);

        if (!this.hasInputTarget || !this.hasAlertTarget) {
            return;
        }

        this.inputTarget.addEventListener("input", this.toggle);
        this.toggle();
    }

    disconnect() {
        if (this.hasInputTarget) {
            this.inputTarget.removeEventListener("input", this.toggle);
        }
    }

    toggle() {
        const initialEmail = this.normalize(this.initialValue || this.inputTarget.defaultValue);
        const currentEmail = this.normalize(this.inputTarget.value);
        const hasChanged = currentEmail !== initialEmail;

        this.alertTarget.classList.toggle("d-none", !hasChanged);
        this.alertTarget.setAttribute("aria-hidden", hasChanged ? "false" : "true");

        if (this.hasPasswordWrapperTarget) {
            this.passwordWrapperTarget.classList.toggle("d-none", !hasChanged);
            this.passwordWrapperTarget.setAttribute("aria-hidden", hasChanged ? "false" : "true");
        }

        if (this.hasPasswordTarget) {
            this.passwordTarget.required = hasChanged;

            if (!hasChanged) {
                this.passwordTarget.value = "";
            }
        }
    }

    normalize(value) {
        return value.trim().toLowerCase();
    }
}
