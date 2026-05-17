import "./stimulus_bootstrap.js";
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import "bootstrap/dist/css/bootstrap.min.css";
import "@fortawesome/fontawesome-free/css/all.css";
import "bootstrap-icons/font/bootstrap-icons.min.css";
import "./styles/app.css";
// import 'bootstrap';
import * as bootstrap from "bootstrap";

document.addEventListener("turbo:load", () => {
    document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach((el) => {
        if (bootstrap.Tooltip.getInstance(el)) return;
        new bootstrap.Tooltip(el);
    });
});

const closeAlertMessage = () => {
    const alert = document.querySelector(".alert");
    if (alert) {
        setTimeout(() => {
            alert.classList.add("fade-out");
            setTimeout(() => alert.remove(), 1000);
        }, 4000);
    }
};

const initPage = () => {
    closeAlertMessage();
}

console.log("This log comes from assets/app.js - welcome to AssetMapper! 🎉");
document.addEventListener('load', initPage);
document.addEventListener('turbo:load', initPage);
