import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
    static values = {
        url: String,
        interval: { type: Number, default: 4000 },
        state: String,
    };

    connect() {
        this.timer = null;

        if (this.stateValue !== "processing") {
            return;
        }

        this.startPolling();
    }

    disconnect() {
        this.stopPolling();
    }

    startPolling() {
        this.stopPolling();

        this.timer = window.setInterval(() => {
            this.refreshStatus();
        }, this.intervalValue);
    }

    stopPolling() {
        if (this.timer) {
            window.clearInterval(this.timer);
            this.timer = null;
        }
    }

    async refreshStatus() {
        try {
            const response = await fetch(this.urlValue, {
                headers: {
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
            });

            if (!response.ok) {
                return;
            }

            const data = await response.json();

            if (!data.paymentState) {
                return;
            }

            if (data.paymentState !== "processing") {
                this.stopPolling();
                window.location.reload();
            }
        } catch (error) {
            // On échoue silencieusement, le polling reprendra au cycle suivant.
            console.error(
                "Erreur lors de la vérification du paiement :",
                error,
            );
        }
    }
}
