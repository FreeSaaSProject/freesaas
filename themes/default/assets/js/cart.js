(function () {
  'use strict';

  var i18n = window.SitesCartI18n || {};
  var cartLabel = i18n.navLabel || 'Cart ({n})';
  var cartPlain = i18n.navPlain || 'Cart';
  var checkoutShort = i18n.checkoutShort || 'Checkout?';
  var fabAria = i18n.fabAria || 'Cart, {n} item(s)';

  function tpl(str, n) {
    return String(str).replace(/\{n\}/g, String(n));
  }

  function qs(sel, root) {
    return (root || document).querySelector(sel);
  }

  function qsa(sel, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(sel));
  }

  function updateCartUi(data) {
    var count = parseInt(data.count, 10) || 0;
    var total = data.total != null ? data.total : 0;
    var label = count > 0 ? tpl(cartLabel, count) : cartPlain;

    qsa('[data-cart-link]').forEach(function (a) {
      a.setAttribute('data-cart-count', String(count));
      a.textContent = label;
      if (count > 0) {
        a.classList.add('is-cart-hot');
      }
    });

    qsa('[data-cart-fab]').forEach(function (fab) {
      fab.setAttribute('data-cart-count', String(count));
      fab.setAttribute('data-cart-total', String(total));
      fab.setAttribute('aria-label', tpl(fabAria, count));
      var badge = qs('[data-cart-fab-count]', fab);
      if (badge) {
        badge.textContent = String(count);
      }
      fab.classList.toggle('is-empty', count < 1);
      fab.classList.remove('is-bump');
      void fab.offsetWidth;
      fab.classList.add('is-bump');
    });
  }

  function morphToCheckout(btn, label) {
    var text = label || checkoutShort;
    var form = btn.closest('form');
    var link = document.createElement('a');
    link.className = 'site-button site-button-secondry cd-added-to-cart';
    link.href = '/cart';
    link.textContent = text;
    if (form && form.parentNode) {
      form.parentNode.replaceChild(link, form);
    } else if (btn.parentNode) {
      btn.parentNode.replaceChild(link, btn);
    }
  }

  function flyDot(fromEl) {
    var fab = qs('[data-cart-fab]');
    if (!fab || !fromEl) {
      return;
    }
    var from = fromEl.getBoundingClientRect();
    var to = fab.getBoundingClientRect();
    var dot = document.createElement('span');
    dot.className = 'site-cart-fly';
    dot.setAttribute('aria-hidden', 'true');
    dot.style.left = from.left + from.width / 2 + 'px';
    dot.style.top = from.top + from.height / 2 + 'px';
    document.body.appendChild(dot);
    requestAnimationFrame(function () {
      dot.style.left = to.left + to.width / 2 + 'px';
      dot.style.top = to.top + to.height / 2 + 'px';
      dot.style.opacity = '0.15';
      dot.style.transform = 'scale(0.35)';
    });
    setTimeout(function () {
      if (dot.parentNode) {
        dot.parentNode.removeChild(dot);
      }
    }, 520);
  }

  function addViaAjax(btn, productId, form) {
    if (btn.getAttribute('data-cart-busy') === '1') {
      return;
    }
    btn.setAttribute('data-cart-busy', '1');
    btn.classList.add('is-adding');

    var body = new FormData();
    body.append('add', '1');
    body.append('product_id', String(productId));
    body.append('ajax', '1');
    if (form) {
      var ret = form.querySelector('[name="ret"]');
      if (ret && ret.value) {
        body.append('ret', ret.value);
      }
    }

    fetch('/cart', {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json'
      },
      body: body
    })
      .then(function (res) {
        return res.json().then(function (data) {
          return { okHttp: res.ok, data: data || {} };
        });
      })
      .then(function (pack) {
        var data = pack.data;
        if (!pack.okHttp || !data.ok) {
          throw new Error(data.error || 'cart error');
        }
        flyDot(btn);
        updateCartUi(data);
        morphToCheckout(btn, data.checkout_label || checkoutShort);
      })
      .catch(function () {
        btn.removeAttribute('data-cart-busy');
        btn.classList.remove('is-adding');
        if (form) {
          form.removeEventListener('submit', onFormSubmit, true);
          if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
          } else {
            form.submit();
          }
        }
      });
  }

  function productIdFrom(el, form) {
    if (form) {
      var input = form.querySelector('[name="product_id"]');
      if (input && input.value) {
        return parseInt(String(input.value), 10) || 0;
      }
    }
    var uid = el.getAttribute('data-uid') || el.getAttribute('data-product-id');
    return parseInt(String(uid || '0'), 10) || 0;
  }

  function isAddForm(form) {
    if (!form) {
      return false;
    }
    if (!form.querySelector('[name="product_id"]')) {
      return false;
    }
    if (form.querySelector('[name="add"], .cd-add-to-cart, button[name="add"]')) {
      return true;
    }
    var action = (form.getAttribute('action') || '').toLowerCase();
    return action.indexOf('/cart') !== -1;
  }

  function onFormSubmit(e) {
    var form = e.target;
    if (!isAddForm(form)) {
      return;
    }
    var btn =
      form.querySelector('.cd-add-to-cart') ||
      form.querySelector('button[name="add"]') ||
      form.querySelector('button[type="submit"]') ||
      form.querySelector('button');
    if (!btn) {
      return;
    }
    var pid = productIdFrom(btn, form);
    if (pid < 1) {
      return;
    }
    e.preventDefault();
    addViaAjax(btn, pid, form);
  }

  function onClick(e) {
    var btn = e.target.closest('.cd-add-to-cart[data-uid], .cd-add-to-cart[data-product-id]');
    if (!btn) {
      return;
    }
    if (btn.closest('form')) {
      return;
    }
    e.preventDefault();
    var pid = productIdFrom(btn, null);
    if (pid < 1) {
      return;
    }
    addViaAjax(btn, pid, null);
  }

  document.addEventListener('submit', onFormSubmit, true);
  document.addEventListener('click', onClick, false);
})();
