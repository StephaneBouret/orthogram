export const PasswordStrength = {
    STRENGTH_VERY_WEAK: 'Très faible',
    STRENGTH_WEAK: 'Faible',
    STRENGTH_MEDIUM: 'Moyen',
    STRENGTH_STRONG: 'Fort',
    STRENGTH_VERY_STRONG: 'Très fort',
}

export function evaluatePasswordStrength(password) {
    // On calcule la longueur du mot de passe
    let length = password.length;

    // Si le mot de passe est vide
    if (!length) {
        return PasswordStrength.STRENGTH_VERY_WEAK;
    }

    // On crée un objet qui contiendra les caractères et leur nombre
    let passwordChars = {};

    for (let i = 0; i < password.length; i++) {
        let charCode = password.charCodeAt(i);
        passwordChars[charCode] = (passwordChars[charCode] || 0) + 1;
    }

    // Compte le nombre de caractères différents dans le mot de passe
    let chars = Object.keys(passwordChars).length;

    // On initialise les variables des types de caractères
    let control = 0,
        digit = 0,
        upper = 0,
        lower = 0,
        symbol = 0,
        other = 0;

    for (let [chr] of Object.entries(passwordChars)) {
        chr = Number(chr);
        if (chr < 32 || chr === 127) {
            // Caractère de contrôle
            control = 33;
        } else if (chr >= 48 && chr <= 57) {
            // Chiffres
            digit = 10;
        } else if (chr >= 65 && chr <= 90) {
            // Majuscules
            upper = 26;
        } else if (chr >= 97 && chr <= 122) {
            // Minuscules
            lower = 26;
        } else if (chr >= 128) {
            // Autres caractères
            other = 128;
        } else {
            // Symboles
            symbol = 33;
        }
    }

    // On calcule le pool de caractère
    let pool = control + digit + upper + lower + other + symbol;

    // Formule de calcul de l'entropie
    let entropy = chars * Math.log2(pool) + (length - chars) * Math.log2(chars);

    if (entropy >= 120) return PasswordStrength.STRENGTH_VERY_STRONG;
    if (entropy >= 100) return PasswordStrength.STRENGTH_STRONG;
    if (entropy >= 80) return PasswordStrength.STRENGTH_MEDIUM;
    if (entropy >= 60) return PasswordStrength.STRENGTH_WEAK;
    return PasswordStrength.STRENGTH_VERY_WEAK;
}

// Génération du mot de passe sécurisé
export function generateStrongPassword(length = 24) {
    // const lowercase = "abcdefghijklmnopqrstuvwxyz";
    // const uppercase = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
    // const numbers = "0123456789";
    // const symbols = "!@#$%^&*()_+{}[]<>?=-";
    // const all = lowercase + uppercase + numbers + symbols;

    const charset = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+{}[]<>?=-";
    const array = new Uint32Array(length);
    window.crypto.getRandomValues(array);
    return Array.from(array, x => charset[x % charset.length]).join('');

    // let password = "";
    // password += lowercase[Math.floor(Math.random() * lowercase.length)];
    // password += uppercase[Math.floor(Math.random() * uppercase.length)];
    // password += numbers[Math.floor(Math.random() * numbers.length)];
    // password += symbols[Math.floor(Math.random() * symbols.length)];

    // for (let i = 4; i < length; i++) {
    //     password += all[Math.floor(Math.random() * all.length)];
    // }

    // return password.split('').sort(() => Math.random() - 0.5).join('');
}

export function updateEntropy(entropyElement, entropy) {
    entropyElement.classList.remove(
        "text-very-weak",
        "text-weak",
        "text-medium",
        "text-strong",
        "text-very-strong"
    );

    let isStrongEnough = false;

    switch (entropy) {
        case 'Très faible':
            entropyElement.classList.add("text-very-weak");
            break;
        case 'Faible':
            entropyElement.classList.add("text-weak");
            break;
        case 'Moyen':
            entropyElement.classList.add("text-medium");
            break;
        case 'Fort':
            entropyElement.classList.add("text-strong");
            isStrongEnough = true;
            break;
        case 'Très fort':
            entropyElement.classList.add("text-very-strong");
            isStrongEnough = true;
            break;
        default:
            entropyElement.classList.add("text-very-weak");
    }

    entropyElement.textContent = entropy;
    return isStrongEnough;
}

export function bindPasswordGenerator(generateBtn, passwordInput) {
    if (generateBtn && passwordInput) {
        generateBtn.addEventListener("click", () => {
            const icon = generateBtn.querySelector("i");
            const label = generateBtn.querySelector("span");

            icon.classList.remove("bi-shuffle");
            icon.classList.add("bi-arrow-repeat", "spin");

            const newPassword = generateStrongPassword(24);
            passwordInput.value = newPassword;
            passwordInput.dispatchEvent(new Event('input'));

            if (!navigator.clipboard) {
                label.textContent = "Généré";
                icon.className = "bi bi-shuffle";

                setTimeout(() => {
                    label.textContent = "Générer";
                }, 2000);

                return;
            }

            navigator.clipboard.writeText(newPassword).then(() => {
                icon.className = "bi bi-clipboard-check text-success";
                label.textContent = "Copié !";

                setTimeout(() => {
                    label.textContent = "Générer";
                    icon.className = "bi bi-shuffle";
                }, 2000);
            }).catch(() => {
                icon.className = "bi bi-exclamation-triangle text-danger";
                label.textContent = "Erreur";

                setTimeout(() => {
                    label.textContent = "Générer";
                    icon.className = "bi bi-shuffle";
                }, 2000);
            });
        });
    }
}
