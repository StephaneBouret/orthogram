// This is your test publishable API key.
const stripe = Stripe(stripePublicKey);

let elements;

initialize();

document
    .querySelector("#payment-form")
    .addEventListener("submit", handleSubmit);

// Fetches a payment intent and captures the client secret
async function initialize() {
    elements = stripe.elements({
        clientSecret,
        appearance: getStripeAppearance(),
    });

    const paymentElementOptions = {
        layout: "accordion",
    };

    const paymentElement = elements.create("payment", paymentElementOptions);
    paymentElement.mount("#payment-element");

    observeThemeChanges();
}

function isDarkTheme() {
    return document.documentElement.dataset.bsTheme === "dark";
}

function getStripeAppearance() {
    if (isDarkTheme()) {
        return {
            theme: "night",
            variables: {
                colorPrimary: "#f59e0b",
                colorBackground: "#101720",
                colorText: "#f8fafc",
                colorDanger: "#f87171",
                colorTextSecondary: "#cbd5e1",
                colorTextPlaceholder: "#9aa6b2",
                iconColor: "#cbd5e1",
                iconCardErrorColor: "#f87171",
                iconCardCvcColor: "#cbd5e1",
                tabIconColor: "#cbd5e1",
                tabIconSelectedColor: "#f59e0b",
                borderRadius: "8px",
                fontFamily: "Inter, system-ui, sans-serif",
                spacingUnit: "5px",
            },
            rules: {
                ".Input": {
                    backgroundColor: "#151b22",
                    borderColor: "rgba(203, 213, 225, 0.22)",
                    boxShadow: "none",
                },
                ".Input:focus": {
                    borderColor: "#f59e0b",
                    boxShadow: "0 0 0 3px rgba(245, 158, 11, 0.28)",
                },
                ".Tab": {
                    backgroundColor: "#101720",
                    borderColor: "rgba(203, 213, 225, 0.22)",
                    boxShadow: "none",
                },
                ".Tab--selected": {
                    borderColor: "#f59e0b",
                    boxShadow: "0 0 0 1px #f59e0b",
                },
                ".Label": {
                    color: "#f8fafc",
                },
            },
        };
    }

    return {
        theme: "stripe",
        variables: {
            colorPrimary: "#f59e0b",
            borderRadius: "8px",
            fontFamily: "Inter, system-ui, sans-serif",
        },
    };
}

function observeThemeChanges() {
    const observer = new MutationObserver(() => {
        elements.update({ appearance: getStripeAppearance() });
    });

    observer.observe(document.documentElement, {
        attributes: true,
        attributeFilter: ["data-bs-theme"],
    });
}

async function handleSubmit(e) {
    e.preventDefault();
    setLoading(true);

    const { error } = await stripe.confirmPayment({
        elements,
        confirmParams: {
            // Make sure to change this to your payment completion page
            return_url: redirectAfterSuccessUrl,
        },
    });

    // This point will only be reached if there is an immediate error when
    // confirming the payment. Otherwise, your customer will be redirected to
    // your `return_url`. For some payment methods like iDEAL, your customer will
    // be redirected to an intermediate site first to authorize the payment, then
    // redirected to the `return_url`.
    if (error.type === "card_error" || error.type === "validation_error") {
        showMessage(error.message);
    } else {
        showMessage("An unexpected error occurred.");
    }

    setLoading(false);
}

// ------- UI helpers -------

function showMessage(messageText) {
    const messageContainer = document.querySelector("#payment-message");

    messageContainer.classList.remove("hidden");
    messageContainer.textContent = messageText;

    setTimeout(function () {
        messageContainer.classList.add("hidden");
        messageContainer.textContent = "";
    }, 4000);
}

// Show a spinner on payment submission
function setLoading(isLoading) {
    if (isLoading) {
        // Disable the button and show a spinner
        document.querySelector("#submit").disabled = true;
        document.querySelector("#spinner").classList.remove("hidden");
        document.querySelector("#button-text").classList.add("hidden");
    } else {
        document.querySelector("#submit").disabled = false;
        document.querySelector("#spinner").classList.add("hidden");
        document.querySelector("#button-text").classList.remove("hidden");
    }
}
