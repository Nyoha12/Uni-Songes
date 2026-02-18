const FORM_ENDPOINT = "REPLACE_WITH_ENDPOINT_URL";

const form = document.getElementById("contactForm");
const statusBox = document.getElementById("formStatus");
const subjectSelect = document.getElementById("subjectChoice");
const otherTitleWrapper = document.getElementById("otherTitleWrapper");
const otherTitleInput = document.getElementById("otherTitle");
const honeypotInput = document.getElementById("company");
const fullNameInput = document.getElementById("fullName");
const emailInput = document.getElementById("email");
const phoneInput = document.getElementById("phone");
const messageInput = document.getElementById("message");

function setStatus(message, type) {
  if (!statusBox) {
    return;
  }

  statusBox.textContent = message;
  statusBox.dataset.status = type || "info";
}

function safeTrim(value) {
  if (typeof value !== "string") {
    return "";
  }

  return value.trim();
}

function toggleOtherTitle() {
  const isOther = subjectSelect && subjectSelect.value === "Autre";

  if (!otherTitleWrapper || !otherTitleInput) {
    return;
  }

  otherTitleWrapper.hidden = !isOther;
  otherTitleInput.required = !!isOther;

  if (!isOther) {
    otherTitleInput.value = "";
  }
}

if (subjectSelect) {
  subjectSelect.addEventListener("change", toggleOtherTitle);
  toggleOtherTitle();
}

if (form) {
  form.addEventListener("submit", async function (event) {
    event.preventDefault();

    if (honeypotInput && safeTrim(honeypotInput.value)) {
      setStatus("Impossible d’envoyer la demande. Merci de réessayer.", "error");
      return;
    }

    if (FORM_ENDPOINT.indexOf("REPLACE_") !== -1) {
      setStatus("Formulaire pas encore connecté (endpoint manquant).", "error");
      return;
    }

    if (!form.reportValidity()) {
      return;
    }

    const params = new URLSearchParams({
      subjectChoice: subjectSelect ? subjectSelect.value : "",
      otherTitle: otherTitleInput ? safeTrim(otherTitleInput.value) : "",
      fullName: fullNameInput ? safeTrim(fullNameInput.value) : "",
      email: emailInput ? safeTrim(emailInput.value) : "",
      phone: phoneInput ? safeTrim(phoneInput.value) : "",
      message: messageInput ? messageInput.value : "",
      pageUrl: location.href,
      userAgent: navigator.userAgent,
      honeypot: honeypotInput ? safeTrim(honeypotInput.value) : ""
    });

    setStatus("Envoi en cours…", "info");

    try {
      await fetch(FORM_ENDPOINT, {
        method: "POST",
        headers: {
          "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8"
        },
        body: params.toString()
      });

      setStatus("Message envoyé. Votre demande sera traitée par l’association.", "success");
      form.reset();
      toggleOtherTitle();
    } catch (error) {
      setStatus("Une erreur est survenue. Merci de réessayer plus tard.", "error");
    }
  });
}
