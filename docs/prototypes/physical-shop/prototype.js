"use strict";

document.documentElement.classList.add("has-js");

document.addEventListener("DOMContentLoaded", () => {
  const defaultState = "landing";
  const stateOrder = [
    "landing",
    "category",
    "product-available",
    "product-unavailable",
    "product-variants",
    "cart-empty",
    "cart-filled",
    "delivery",
    "confirmation",
    "mobile",
  ];
  const states = new Map(
    [...document.querySelectorAll("[data-prototype-state]")].map((state) => [
      state.dataset.prototypeState,
      state,
    ]),
  );
  const stateLinks = [...document.querySelectorAll("[data-state-link]")];
  const stateAnnouncer = document.querySelector("#state-announcer");

  const stateFromHash = () => {
    const candidate = window.location.hash.slice(1);
    return states.has(candidate) ? candidate : defaultState;
  };

  const setStateLocation = (stateName, replace = false) => {
    const nextHash = `#${stateName}`;
    if (window.location.hash === nextHash) {
      return;
    }

    const method = replace ? "replaceState" : "pushState";
    window.history[method](null, "", nextHash);
  };

  const activateState = (stateName, options = {}) => {
    const { focusHeading = false, replaceLocation = false } = options;
    const resolvedState = states.has(stateName) ? stateName : defaultState;

    states.forEach((state, key) => {
      const isActive = key === resolvedState;
      state.hidden = !isActive;
      if (isActive) {
        state.removeAttribute("inert");
      } else {
        state.setAttribute("inert", "");
      }
    });

    stateLinks.forEach((link) => {
      if (link.dataset.stateLink === resolvedState) {
        link.setAttribute("aria-current", "page");
      } else {
        link.removeAttribute("aria-current");
      }
    });

    const activeState = states.get(resolvedState);
    const activeTitle = activeState.dataset.stateTitle || "Boutique";
    document.title = `${activeTitle} — prototype fictif Uni-Songes`;
    setStateLocation(resolvedState, replaceLocation);

    if (stateAnnouncer) {
      stateAnnouncer.textContent = `État affiché : ${activeTitle}.`;
    }

    if (focusHeading) {
      const heading = activeState.querySelector("h1");
      heading?.focus({ preventScroll: true });
      activeState.scrollIntoView({ block: "start" });
    }
  };

  document
    .querySelectorAll("[data-state-link], [data-state-target]")
    .forEach((control) => {
      control.addEventListener("click", (event) => {
        const targetState =
          control.dataset.stateLink || control.dataset.stateTarget;
        if (!targetState || !states.has(targetState)) {
          return;
        }

        event.preventDefault();
        activateState(targetState, { focusHeading: true });
      });
    });

  window.addEventListener("hashchange", () => {
    activateState(stateFromHash(), {
      focusHeading: true,
      replaceLocation: true,
    });
  });

  const availableProductForm = document.querySelector(
    "#available-product-form",
  );
  const availableProductStatus = document.querySelector(
    "#available-product-status",
  );

  availableProductForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    if (availableProductStatus) {
      availableProductStatus.textContent =
        "Ajout fictif démontré. Aucun produit, panier ou stock n’a été modifié.";
    }
  });

  const variantForm = document.querySelector("#variant-product-form");
  const variantSelect = document.querySelector("#variant-option");
  const variantButton = document.querySelector("#variant-add-button");
  const variantStatus = document.querySelector("#variant-product-status");

  variantSelect?.addEventListener("change", () => {
    const hasSelection = variantSelect.value !== "";
    if (variantButton) {
      variantButton.disabled = !hasSelection;
    }
    if (variantStatus) {
      variantStatus.textContent = hasSelection
        ? "Option fictive sélectionnée. Le bouton de démonstration est disponible."
        : "Sélection requise.";
    }
  });

  variantForm?.addEventListener("submit", (event) => {
    event.preventDefault();
    if (!variantSelect?.value) {
      variantSelect?.focus();
      return;
    }
    if (variantStatus) {
      variantStatus.textContent =
        "Ajout fictif démontré. Aucune variation ou ligne de panier n’a été créée.";
    }
  });

  const removeCartItem = document.querySelector("#remove-cart-item");
  const cartStatus = document.querySelector("#cart-status");

  removeCartItem?.addEventListener("click", () => {
    if (cartStatus) {
      cartStatus.textContent =
        "L’exemple fictif est retiré de la démonstration.";
    }
    activateState("cart-empty", { focusHeading: true });
  });

  const deliveryDemo = document.querySelector("#delivery-form");
  const deliveryContinue = document.querySelector("#delivery-continue");
  const deliveryStatus = document.querySelector("#delivery-status");

  deliveryContinue?.addEventListener("click", () => {
    const selectedMethod = deliveryDemo?.querySelector(
      'input[name="delivery-method"]:checked',
    );

    if (!selectedMethod) {
      if (deliveryStatus) {
        deliveryStatus.textContent =
          "Choisissez la méthode fictive disponible pour démontrer la confirmation.";
      }
      deliveryDemo
        .querySelector('input[name="delivery-method"]:not(:disabled)')
        ?.focus();
      return;
    }

    if (deliveryStatus) {
      deliveryStatus.textContent =
        "Démonstration validée sans enregistrer l’adresse, la livraison ou un paiement.";
    }
    activateState("confirmation", { focusHeading: true });
  });

  const mobileAction = document.querySelector("[data-mobile-demo-action]");
  const mobileMenu = document.querySelector("[data-mobile-menu]");
  const mobileActionStatus = document.querySelector("#mobile-action-status");

  mobileAction?.addEventListener("click", () => {
    if (mobileActionStatus) {
      mobileActionStatus.textContent =
        "Action fictive annoncée, sans modification de données.";
    }
  });

  mobileMenu?.addEventListener("click", () => {
    if (mobileActionStatus) {
      mobileActionStatus.textContent =
        "Contrôle de menu fictif activé. Aucun drawer n’est ouvert.";
    }
  });

  if (
    stateOrder.every((stateName) => states.has(stateName)) &&
    states.size === stateOrder.length
  ) {
    activateState(stateFromHash(), { replaceLocation: true });
  } else {
    document.documentElement.classList.remove("has-js");
    if (stateAnnouncer) {
      stateAnnouncer.textContent =
        "Le sélecteur d’états est indisponible ; tous les écrans restent visibles.";
    }
  }
});
