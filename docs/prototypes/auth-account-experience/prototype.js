(function () {
  "use strict";

  const states = Array.from(document.querySelectorAll("[data-state]"));
  const stateLinks = Array.from(document.querySelectorAll("[data-state-link]"));
  const statePicker = document.querySelector("[data-state-picker]");
  const currentStateLabel = document.querySelector("[data-current-state]");
  const accountLink = document.querySelector("[data-account-link]");
  const defaultState = "login";

  function requestedState(useDefault) {
    const hash = window.location.hash.slice(1);
    const key = hash.replace(/^state-/, "");
    if (states.some((state) => state.dataset.state === key)) {
      return key;
    }

    return useDefault ? defaultState : null;
  }

  function activateState(key, moveFocus) {
    const activeState = states.find((state) => state.dataset.state === key);
    if (!activeState) {
      return;
    }

    states.forEach((state) => {
      const isActive = state === activeState;
      state.hidden = !isActive;
      state.inert = !isActive;
    });

    stateLinks.forEach((link) => {
      if (link.dataset.stateLink === key) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });

    const label = activeState.dataset.stateLabel || key;
    const pageTitle = activeState.dataset.pageTitle || "Compte";
    currentStateLabel.textContent = `État : ${label}`;
    document.title = `${pageTitle} — prototype Uni-Songes`;

    const authenticated = activeState.dataset.authenticated === "true";
    accountLink.textContent = authenticated ? "Mon compte" : "Se connecter";
    accountLink.href = authenticated ? "/user" : "/user/login";

    if (statePicker && moveFocus) {
      statePicker.open = false;
    }

    if (moveFocus) {
      const focusTarget = activeState.querySelector("[data-state-focus], h1");
      if (focusTarget) {
        focusTarget.focus({ preventScroll: true });
        focusTarget.scrollIntoView({ block: "start" });
      }
    }
  }

  stateLinks.forEach((link) => {
    link.addEventListener("click", () => {
      if (link.hash === window.location.hash) {
        activateState(link.dataset.stateLink, true);
      }
    });
  });

  document.querySelectorAll("[data-prototype-form]").forEach((form) => {
    form.inert = false;
    form.addEventListener("submit", (event) => {
      event.preventDefault();
      let status = form.querySelector("[data-prototype-form-status]");
      if (!status) {
        status = document.createElement("p");
        status.className = "prototype-form-status";
        status.dataset.prototypeFormStatus = "";
        status.setAttribute("role", "status");
        status.setAttribute("aria-live", "polite");
        form.append(status);
      }
      status.textContent = "Démonstration statique : aucune donnée n’a été envoyée.";
    });
  });

  window.addEventListener("hashchange", () => {
    const state = requestedState(false);
    if (state) {
      activateState(state, true);
    }
  });

  activateState(requestedState(true), false);
})();
