import { Controller } from "@hotwired/stimulus";
import {
    evaluatePasswordStrength,
    updateEntropy,
    bindPasswordGenerator,
} from "../modules/passwordUtils.js";

export default class extends Controller {
    static targets = [
        "firstname",
        "lastname",
        "email",
        "address",
        "city",
        "postalCode",
        "phone",
        "rgpd",
        "password",
        "entropy",
        "submit",
        "generate",
    ];

    connect() {
        this.onAnyInput = this.onAnyInput.bind(this);

        // Ecoute sur tous les champs (input + change)
        this._bindField(this.firstnameTarget);
        this._bindField(this.lastnameTarget);
        this._bindField(this.emailTarget);
        this._bindField(this.addressTarget);
        this._bindField(this.postalCodeTarget);
        this._bindField(this.cityTarget);
        this._bindField(this.phoneTarget);
        this._bindField(this.rgpdTarget);
        this._bindField(this.passwordTarget);

        // Bouton générer
        if (this.hasGenerateTarget) {
            bindPasswordGenerator(this.generateTarget, this.passwordTarget);
        }

        // Init (si autofill navigateur)
        this.onAnyInput();
    }

    disconnect() {
        this._unbindField(this.firstnameTarget);
        this._unbindField(this.lastnameTarget);
        this._unbindField(this.emailTarget);
        this._unbindField(this.addressTarget);
        this._unbindField(this.postalCodeTarget);
        this._unbindField(this.cityTarget);
        this._unbindField(this.phoneTarget);
        this._unbindField(this.rgpdTarget);
        this._unbindField(this.passwordTarget);
    }

    _bindField(el) {
        if (!el) return;
        el.addEventListener("input", this.onAnyInput);
        el.addEventListener("change", this.onAnyInput);
    }

    _unbindField(el) {
        if (!el) return;
        el.removeEventListener("input", this.onAnyInput);
        el.removeEventListener("change", this.onAnyInput);
    }

    onAnyInput() {
        const firstnameOk = (this.firstnameTarget.value ?? "").length > 2;
        const lastnameOk = (this.lastnameTarget.value ?? "").length > 1;

        const emailValue = this.emailTarget.value ?? "";
        const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailValue);

        const addressOk = (this.addressTarget.value ?? "").length > 1;

        const postalValue = this.postalCodeTarget.value ?? "";
        const postalOk =
            /^((0[1-9])|([1-8][0-9])|(9[0-8])|(2A)|(2B)) *([0-9]{3})?$/i.test(
                postalValue,
            );

        const cityValue = this.cityTarget.value ?? "";
        const cityOk = /^\s*\p{L}{1}[\p{L}\p{N} '-.=#/]*$/gmu.test(cityValue);

        const phoneValue = this.phoneTarget.value ?? "";
        const phoneOk =
            /(?:([+]\d{1,4})[-.\s]?)?(?:[(](\d{1,3})[)][-.\s]?)?(\d{1,4})[-.\s]?(\d{1,4})[-.\s]?(\d{1,9})/g.test(
                phoneValue,
            );

        const rgpdOk = !!this.rgpdTarget.checked;

        // Password + entropy
        const passwordValue = this.passwordTarget.value ?? "";
        const entropy = evaluatePasswordStrength(passwordValue);
        const passOk = this.hasEntropyTarget
            ? updateEntropy(this.entropyTarget, entropy)
            : entropy === "Fort" || entropy === "Très fort";

        const allOk =
            firstnameOk &&
            lastnameOk &&
            emailOk &&
            addressOk &&
            postalOk &&
            cityOk &&
            phoneOk &&
            rgpdOk &&
            passOk;

        if (allOk) this.submitTarget.removeAttribute("disabled");
        else this.submitTarget.setAttribute("disabled", "disabled");
    }
}
