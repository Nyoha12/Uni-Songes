(() => {
  "use strict";

  const desktopRoot = document.querySelector(
    '[data-navigation-submenus="desktop"]',
  );
  const mobileRoot = document.querySelector(
    '[data-navigation-submenus="mobile"]',
  );

  if (!desktopRoot || !mobileRoot) return;
  if (desktopRoot.classList.contains("is-enhanced")) return;

  const directChild = (element, selector) =>
    Array.from(element.children).find((child) => child.matches(selector));

  const findRootList = (root) => {
    const menuBlock = root.querySelector(".menu--main");
    const blockList = menuBlock && directChild(menuBlock, "ul");

    if (blockList) return blockList;

    const lists = Array.from(root.querySelectorAll("ul")).filter(
      (list) =>
        !list.classList.contains("contextual-links") &&
        !list.closest(".contextual"),
    );

    return (
      lists.find(
        (list) =>
          !lists.some(
            (candidate) => candidate !== list && candidate.contains(list),
          ),
      ) || null
    );
  };

  const desktopList = findRootList(desktopRoot);
  if (!desktopList) return;

  const usedIds = new Set(
    Array.from(document.querySelectorAll("[id]"), (element) => element.id),
  );
  let idSequence = 0;

  const uniqueId = (prefix) => {
    let candidate;

    do {
      idSequence += 1;
      candidate = `${prefix}-${idSequence}`;
    } while (usedIds.has(candidate));

    usedIds.add(candidate);
    return candidate;
  };

  const elementsIncludingRoot = (root, selector) => {
    const elements = Array.from(root.querySelectorAll(selector));
    if (root.matches(selector)) elements.unshift(root);
    return elements;
  };

  const namespaceCloneIds = (clone) => {
    const idMap = new Map();

    elementsIncludingRoot(clone, "[id]").forEach((element) => {
      const previousId = element.id;
      const replacementId = uniqueId("main-navigation-mobile-source");

      if (!idMap.has(previousId)) idMap.set(previousId, replacementId);
      element.id = replacementId;
    });

    const idReferenceAttributes = [
      "aria-activedescendant",
      "aria-controls",
      "aria-describedby",
      "aria-details",
      "aria-labelledby",
      "aria-owns",
      "for",
      "form",
      "headers",
      "list",
    ];

    elementsIncludingRoot(clone, "*").forEach((element) => {
      idReferenceAttributes.forEach((attribute) => {
        if (!element.hasAttribute(attribute)) return;

        const references = element
          .getAttribute(attribute)
          .trim()
          .split(/\s+/)
          .map((reference) => idMap.get(reference) || reference);

        element.setAttribute(attribute, references.join(" "));
      });

      ["data-target", "data-bs-target"].forEach((attribute) => {
        const value = element.getAttribute(attribute);
        if (!value || value.charAt(0) !== "#") return;

        const replacement = idMap.get(value.slice(1));
        if (replacement) element.setAttribute(attribute, `#${replacement}`);
      });

      element.removeAttribute("data-component-id");
      element.removeAttribute("data-once");
    });
  };

  const mobileList = desktopList.cloneNode(true);
  namespaceCloneIds(mobileList);
  mobileRoot.appendChild(mobileList);

  const hasUniqueDocumentId = (element, id) => {
    const matches = Array.from(document.querySelectorAll("[id]")).filter(
      (candidate) => candidate.id === id,
    );

    return matches.length === 1 && matches[0] === element;
  };

  const ensureSubmenuId = (submenu, scope) => {
    if (submenu.id && hasUniqueDocumentId(submenu, submenu.id)) {
      return submenu.id;
    }

    const id = uniqueId(`main-navigation-${scope}-submenu`);
    submenu.id = id;
    return id;
  };

  const defer = window.queueMicrotask
    ? window.queueMicrotask.bind(window)
    : (callback) => Promise.resolve().then(callback);
  const hoverQuery = window.matchMedia
    ? window.matchMedia("(hover: hover) and (pointer: fine)")
    : { matches: false };

  const createController = (root, rootList, scope) => {
    const records = [];
    const recordsByItem = new WeakMap();
    const desktop = scope === "desktop";

    const accessibleLabel = (expanded, label) => {
      const action = expanded ? "Masquer" : "Afficher";
      return label
        ? `${action} le sous-menu de ${label}`
        : `${action} le sous-menu`;
    };

    const normalizeParentLink = (link) => {
      if (!link) return;

      link.classList.remove("dropdown-toggle");
      link.removeAttribute("aria-controls");
      link.removeAttribute("aria-expanded");
      link.removeAttribute("aria-haspopup");
      link.removeAttribute("data-bs-target");
      link.removeAttribute("data-bs-toggle");
      link.removeAttribute("data-target");
      link.removeAttribute("data-toggle");

      if (link.getAttribute("role") === "button") {
        link.removeAttribute("role");
      }
    };

    const enhanceList = (list, depth) => {
      list.classList.add("navigation-submenus__list");
      list.classList.add(
        depth === 0
          ? "navigation-submenus__list--root"
          : "navigation-submenus__submenu",
      );
      list.dataset.navigationLevel = String(depth);

      Array.from(list.children)
        .filter((child) => child.matches("li"))
        .forEach((item) => {
          item.classList.add("navigation-submenus__item");
          item.classList.add(
            depth === 0
              ? "navigation-submenus__item--top"
              : "navigation-submenus__item--nested",
          );

          const submenu = directChild(item, "ul");
          if (!submenu) return;

          const link = directChild(item, "a");
          const labelElement = link || directChild(item, "span");
          const label = labelElement
            ? labelElement.textContent.replace(/\s+/gu, " ").trim()
            : "";
          const row = document.createElement("div");
          const toggle = document.createElement("button");
          const submenuId = ensureSubmenuId(submenu, scope);

          item.classList.add("navigation-submenus__item--parent");
          item.classList.remove("is-open", "show");
          row.className = "navigation-submenus__parent-row";
          toggle.className = "navigation-submenus__toggle";
          toggle.type = "button";
          toggle.setAttribute("aria-controls", submenuId);
          toggle.setAttribute("aria-expanded", "false");
          toggle.setAttribute("aria-label", accessibleLabel(false, label));

          normalizeParentLink(link);
          submenu.classList.remove("show");
          submenu.hidden = true;

          if (labelElement) {
            item.insertBefore(row, labelElement);
            row.appendChild(labelElement);
          } else {
            item.insertBefore(row, submenu);
          }
          row.appendChild(toggle);

          const record = {
            depth,
            item,
            label,
            list,
            row,
            submenu,
            toggle,
          };

          records.push(record);
          recordsByItem.set(item, record);
          enhanceList(submenu, depth + 1);
        });
    };

    enhanceList(rootList, 0);

    const expanded = (record) =>
      record.toggle.getAttribute("aria-expanded") === "true";

    const positionPanel = (record) => {
      if (!desktop || record.depth !== 0 || !expanded(record)) return;
      if (document.body.classList.contains("compact-nav")) return;

      const triggerBounds = record.row.getBoundingClientRect();
      if (!triggerBounds.width) return;

      const edge = 8;
      const viewportWidth = document.documentElement.clientWidth;
      record.submenu.style.visibility = "hidden";
      record.submenu.style.setProperty(
        "--navigation-submenu-trigger-width",
        `${Math.ceil(triggerBounds.width)}px`,
      );
      record.submenu.style.setProperty("--navigation-submenu-left", "0px");
      record.submenu.style.setProperty("--navigation-submenu-top", "0px");

      const panelWidth = Math.min(
        record.submenu.getBoundingClientRect().width,
        Math.max(0, viewportWidth - edge * 2),
      );
      const maximumLeft = Math.max(edge, viewportWidth - panelWidth - edge);
      const left = Math.min(Math.max(triggerBounds.left, edge), maximumLeft);
      const top = triggerBounds.bottom + 6;

      record.submenu.style.setProperty(
        "--navigation-submenu-left",
        `${Math.round(left)}px`,
      );
      record.submenu.style.setProperty(
        "--navigation-submenu-top",
        `${Math.round(top)}px`,
      );
      record.submenu.style.removeProperty("visibility");
    };

    const setExpanded = (record, shouldExpand) => {
      record.toggle.setAttribute("aria-expanded", String(shouldExpand));
      record.toggle.setAttribute(
        "aria-label",
        accessibleLabel(shouldExpand, record.label),
      );
      record.item.classList.toggle("is-open", shouldExpand);
      record.submenu.hidden = !shouldExpand;

      if (shouldExpand) {
        positionPanel(record);
      } else {
        record.submenu.style.removeProperty("--navigation-submenu-left");
        record.submenu.style.removeProperty("--navigation-submenu-top");
        record.submenu.style.removeProperty(
          "--navigation-submenu-trigger-width",
        );
        record.submenu.style.removeProperty("visibility");
      }
    };

    const closeBranch = (record) => {
      records
        .filter(
          (candidate) =>
            candidate !== record && record.item.contains(candidate.item),
        )
        .sort((a, b) => b.depth - a.depth)
        .forEach((candidate) => setExpanded(candidate, false));
      setExpanded(record, false);
    };

    const closePeers = (record) => {
      records
        .filter(
          (candidate) => candidate !== record && candidate.list === record.list,
        )
        .forEach(closeBranch);
    };

    const openBranch = (record) => {
      const chain = records
        .filter(
          (candidate) =>
            candidate === record || candidate.item.contains(record.item),
        )
        .sort((a, b) => a.depth - b.depth);

      chain.forEach((candidate) => {
        closePeers(candidate);
        setExpanded(candidate, true);
      });
    };

    const closeAll = () => {
      records
        .slice()
        .sort((a, b) => b.depth - a.depth)
        .forEach((record) => setExpanded(record, false));
    };

    const recordForElement = (element) => {
      if (!(element instanceof Element)) return null;

      const item = element.closest(".navigation-submenus__item--parent");
      return item && root.contains(item)
        ? recordsByItem.get(item) || null
        : null;
    };

    records.forEach((record) => {
      record.toggle.addEventListener("pointerdown", () => {
        record.pointerFocus = true;
        window.setTimeout(() => {
          record.pointerFocus = false;
        }, 0);
      });

      record.toggle.addEventListener("click", (event) => {
        event.preventDefault();
        event.stopPropagation();

        if (expanded(record)) closeBranch(record);
        else openBranch(record);
      });

      if (!desktop) return;

      record.item.addEventListener("pointerenter", () => {
        if (!hoverQuery.matches) return;
        if (document.body.classList.contains("compact-nav")) return;
        if (
          root.contains(document.activeElement) &&
          !record.item.contains(document.activeElement)
        ) {
          return;
        }
        openBranch(record);
      });

      record.item.addEventListener("pointerleave", () => {
        if (!hoverQuery.matches) return;
        if (record.item.contains(document.activeElement)) return;
        closeBranch(record);
      });
    });

    root.addEventListener("focusin", (event) => {
      const record = recordForElement(event.target);
      if (!record) return;
      if (event.target === record.toggle && record.suppressFocusOpen) return;
      if (event.target === record.toggle && record.pointerFocus) return;
      openBranch(record);
    });

    root.addEventListener("focusout", (event) => {
      if (!(event.target instanceof Element)) return;

      const affected = records.filter((record) =>
        record.item.contains(event.target),
      );

      defer(() => {
        affected
          .sort((a, b) => b.depth - a.depth)
          .forEach((record) => {
            if (record.item.contains(document.activeElement)) return;
            if (
              desktop &&
              hoverQuery.matches &&
              record.item.matches(":hover")
            ) {
              return;
            }
            closeBranch(record);
          });
      });
    });

    root.addEventListener("keydown", (event) => {
      if (event.key !== "Escape") return;
      if (!(event.target instanceof Element)) return;

      const record = records
        .filter(
          (candidate) =>
            expanded(candidate) && candidate.item.contains(event.target),
        )
        .sort((a, b) => b.depth - a.depth)[0];

      if (!record) return;

      const focusWasInSubmenu = record.submenu.contains(event.target);
      closeBranch(record);
      if (focusWasInSubmenu) {
        record.suppressFocusOpen = true;
        record.toggle.focus();
        record.suppressFocusOpen = false;
      }
      event.preventDefault();
      event.stopPropagation();
    });

    root.classList.add("is-enhanced");

    return {
      closeAll,
      hasOpenPanel: () => records.some(expanded),
      positionOpenPanels: () => records.filter(expanded).forEach(positionPanel),
      root,
    };
  };

  const desktopController = createController(
    desktopRoot,
    desktopList,
    "desktop",
  );
  const mobileController = createController(mobileRoot, mobileList, "mobile");
  const controllers = [desktopController, mobileController];

  document.addEventListener(
    "pointerdown",
    (event) => {
      controllers.forEach((controller) => {
        if (!controller.root.contains(event.target)) controller.closeAll();
      });
    },
    { passive: true },
  );

  document.addEventListener("keydown", (event) => {
    if (event.key !== "Escape" || event.defaultPrevented) return;
    if (document.body.classList.contains("compact-nav")) return;
    if (!desktopController.hasOpenPanel()) return;

    desktopController.closeAll();
    event.preventDefault();
  });

  let compact = document.body.classList.contains("compact-nav");
  const compactObserver = new MutationObserver(() => {
    const nextCompact = document.body.classList.contains("compact-nav");
    if (nextCompact === compact) return;

    compact = nextCompact;
    controllers.forEach((controller) => controller.closeAll());
  });
  compactObserver.observe(document.body, {
    attributeFilter: ["class"],
    attributes: true,
  });

  const drawerToggle = document.querySelector(".nav-toggle");
  if (drawerToggle) {
    const drawerObserver = new MutationObserver(() => {
      if (drawerToggle.getAttribute("aria-expanded") === "false") {
        mobileController.closeAll();
      }
    });
    drawerObserver.observe(drawerToggle, {
      attributeFilter: ["aria-expanded"],
      attributes: true,
    });
  }

  let positionFrame = 0;
  const schedulePosition = () => {
    if (positionFrame) return;
    positionFrame = window.requestAnimationFrame(() => {
      positionFrame = 0;
      desktopController.positionOpenPanels();
    });
  };

  window.addEventListener("resize", schedulePosition, { passive: true });
  window.addEventListener("scroll", schedulePosition, { passive: true });

  if (document.fonts && document.fonts.ready) {
    document.fonts.ready.then(schedulePosition).catch(() => {});
  }
})();
