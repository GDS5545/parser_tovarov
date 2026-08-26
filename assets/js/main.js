(function () {
  "use strict";

  // Footer year
  var yearEl = document.getElementById("year");
  if (yearEl) yearEl.textContent = new Date().getFullYear();

  // Mobile nav toggle
  var burger = document.getElementById("burgerBtn");
  var mobileNav = document.getElementById("mobileNav");
  if (burger && mobileNav) {
    burger.addEventListener("click", function () {
      mobileNav.classList.toggle("open");
    });
    mobileNav.querySelectorAll("a").forEach(function (a) {
      a.addEventListener("click", function () {
        mobileNav.classList.remove("open");
      });
    });
  }

  // Catalog filter tabs
  var tabs = document.querySelectorAll(".tab-btn");
  var cards = document.querySelectorAll(".product-card");
  tabs.forEach(function (tab) {
    tab.addEventListener("click", function () {
      tabs.forEach(function (t) { t.classList.remove("active"); });
      tab.classList.add("active");
      var filter = tab.getAttribute("data-filter");
      cards.forEach(function (card) {
        var show = filter === "all" || card.getAttribute("data-cat") === filter;
        card.style.display = show ? "" : "none";
      });
    });
  });

  // FAQ accordion
  document.querySelectorAll(".faq-item").forEach(function (item) {
    var q = item.querySelector(".faq-q");
    q.addEventListener("click", function () {
      var isOpen = item.classList.contains("open");
      document.querySelectorAll(".faq-item").forEach(function (i) { i.classList.remove("open"); });
      if (!isOpen) item.classList.add("open");
    });
  });

  // Modal (callback form)
  var overlay = document.getElementById("modalOverlay");
  var openButtons = document.querySelectorAll("[data-open-modal]");
  var closeBtn = document.getElementById("modalClose");
  openButtons.forEach(function (btn) {
    btn.addEventListener("click", function () { overlay.classList.add("open"); });
  });
  if (closeBtn) closeBtn.addEventListener("click", function () { overlay.classList.remove("open"); });
  if (overlay) {
    overlay.addEventListener("click", function (e) {
      if (e.target === overlay) overlay.classList.remove("open");
    });
  }
  document.addEventListener("keydown", function (e) {
    if (e.key === "Escape" && overlay) overlay.classList.remove("open");
  });

  // Forms (front-end only — no backend wired up yet)
  function handleFormSubmit(form, statusEl) {
    if (!form) return;
    form.addEventListener("submit", function (e) {
      e.preventDefault();
      statusEl.textContent = "Спасибо! Мы свяжемся с вами в ближайшее время.";
      statusEl.classList.add("ok");
      form.reset();
      setTimeout(function () {
        statusEl.textContent = "";
        statusEl.classList.remove("ok");
        if (overlay) overlay.classList.remove("open");
      }, 3000);
    });
  }
  handleFormSubmit(document.getElementById("contactForm"), document.getElementById("formStatus"));
  handleFormSubmit(document.getElementById("modalForm"), document.getElementById("modalStatus"));

  // Scroll reveal animations
  var animated = document.querySelectorAll("[data-animate]");
  if ("IntersectionObserver" in window) {
    var observer = new IntersectionObserver(
      function (entries) {
        entries.forEach(function (entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add("in-view");
            observer.unobserve(entry.target);
          }
        });
      },
      { threshold: 0.15 }
    );
    animated.forEach(function (el) { observer.observe(el); });
  } else {
    animated.forEach(function (el) { el.classList.add("in-view"); });
  }
})();
