const FORM_ENDPOINT = "REPLACE_WITH_ENDPOINT_URL";

const form = document.getElementById("contactForm");
const statusBox = document.getElementById("formStatus");
const subjectSelect = document.getElementById("subjectChoice");
const otherTitleWrapper = document.getElementById("otherTitleWrapper");
const otherTitleInput = document.getElementById("otherTitle");
const honeypotInput = document.getElementById("company");

function setStatus(message, type = "info") {
  if (!statusBox) {
    return;
  }

  statusBox.textContent = message;
  statusBox.dataset.status = type;
}

function toggleOtherTitle() {
  const isOther = subjectSelect?.value === "Autre";

  if (!otherTitleWrapper || !otherTitleInput) {
    return;
  }

  otherTitleWrapper.hidden = !isOther;
  otherTitleInput.required = Boolean(isOther);

  if (!isOther) {
    otherTitleInput.value = "";
  }
}

if (subjectSelect) {
  subjectSelect.addEventListener("change", toggleOtherTitle);
  toggleOtherTitle();
}

if (form) {
  form.addEventListener("submit", async (event) => {
    event.preventDefault();

    if (honeypotInput?.value?.trim()) {
      setStatus("Impossible d’envoyer la demande. Merci de réessayer.", "error");
      return;
    }

    if (FORM_ENDPOINT.includes("REPLACE_")) {
      setStatus("Formulaire pas encore connecté (endpoint manquant).", "error");
      return;
    }

    if (!form.reportValidity()) {
      return;
    }

    const params = new URLSearchParams({
      subjectChoice: subjectSelect?.value || "",
      otherTitle: otherTitleInput?.value?.trim() || "",
      fullName: document.getElementById("fullName")?.value?.trim() || "",
      email: document.getElementById("email")?.value?.trim() || "",
      phone: document.getElementById("phone")?.value?.trim() || "",
      message: document.getElementById("message")?.value || "",
      pageUrl: location.href,
      userAgent: navigator.userAgent,
      honeypot: honeypotInput?.value?.trim() || ""
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
    } catch {
      setStatus("Une erreur est survenue. Merci de réessayer plus tard.", "error");
    }
  });
}
