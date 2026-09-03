(function () {
    'use strict';

    function initConfigurators() {
        var config = window.ppwAjax && window.ppwAjax.config ? clone(window.ppwAjax.config) : null;
        if (!config || !Array.isArray(config.params)) {
            return;
        }

        document.querySelectorAll('form.cart').forEach(function (form) {
            var root = form.querySelector('[data-ppw-configurator]');
            var hidden = form.querySelector('input[name="ppw_config"]');

            if (!root || !hidden || root.dataset.ppwReady === '1') {
                return;
            }

            root.dataset.ppwReady = '1';
            root.ppwState = clone(config);
            applyDefaults(root.ppwState.params);
            renderConfigurator(root, hidden);
        });
    }

    function applyDefaults(params) {
        (params || []).forEach(function (param) {
            if (!Object.prototype.hasOwnProperty.call(param, 'value') || param.value === null) {
                if (Object.prototype.hasOwnProperty.call(param, 'defaultValue')) {
                    param.value = clone(param.defaultValue);
                } else if (param.type === 'multi-select') {
                    param.value = [];
                }
            }

            if (Array.isArray(param.children)) {
                applyDefaults(param.children);
            }

            if (Array.isArray(param.options)) {
                param.options.forEach(function (option) {
                    if (Array.isArray(option.children)) {
                        applyDefaults(option.children);
                    }
                });
            }
        });
    }

    function renderConfigurator(root, hidden) {
        root.innerHTML = '';

        var title = document.createElement('div');
        title.className = 'ppw-configurator__title';
        title.textContent = 'Параметры печати';
        root.appendChild(title);

        var body = document.createElement('div');
        body.className = 'ppw-configurator__body';
        root.appendChild(body);

        renderParams(root.ppwState.params, body, root, hidden, 0);
        syncHidden(root, hidden);
    }

    function renderParams(params, container, root, hidden, level) {
        (params || []).forEach(function (param) {
            if (param.hidden === true) {
                return;
            }

            var field = document.createElement('div');
            field.className = 'ppw-config-field ppw-config-field--' + escapeAttr(param.type || 'unknown');
            field.style.setProperty('--ppw-level', String(level || 0));

            var label = document.createElement('div');
            label.className = 'ppw-config-field__label';
            label.innerHTML = '<strong>' + escapeHtml(param.label || '') + '</strong>' + (param.required ? ' <span class="ppw-required">*</span>' : '');
            field.appendChild(label);

            if (param.shortDescription || param.fullDescription || param.link) {
                var desc = document.createElement('div');
                desc.className = 'ppw-config-field__desc';
                desc.textContent = param.shortDescription || param.fullDescription || '';
                if (param.link) {
                    var link = document.createElement('a');
                    link.href = param.link;
                    link.target = '_blank';
                    link.rel = 'noopener';
                    link.textContent = ' Подробнее';
                    desc.appendChild(link);
                }
                field.appendChild(desc);
            }

            var control = document.createElement('div');
            control.className = 'ppw-config-field__control';
            field.appendChild(control);

            renderControl(param, control, root, hidden);

            var childrenContainer = document.createElement('div');
            childrenContainer.className = 'ppw-config-field__children';
            field.appendChild(childrenContainer);

            container.appendChild(field);
            renderVisibleChildren(param, childrenContainer, root, hidden, level + 1);
        });
    }

    function normalizeNumberValue(value, min, max, step) {
        if (value === null || value === undefined || value === '') {
            return null;
        }

        var number = Number(value);
        if (Number.isNaN(number)) {
            return null;
        }

        var minNumber = (min !== null && min !== undefined && min !== '' && !Number.isNaN(Number(min))) ? Number(min) : 0;
        var maxNumber = (max !== null && max !== undefined && max !== '' && !Number.isNaN(Number(max))) ? Number(max) : null;
        var stepNumber = (step !== null && step !== undefined && step !== '' && !Number.isNaN(Number(step)) && Number(step) > 0) ? Number(step) : 1;

        if (number < minNumber) {
            number = minNumber;
        }

        if (maxNumber !== null && number > maxNumber) {
            number = maxNumber;
        }

        number = minNumber + Math.floor((number - minNumber) / stepNumber) * stepNumber;

        var decimals = 0;
        var stepString = String(stepNumber);
        if (stepString.indexOf('.') !== -1) {
            decimals = stepString.split('.')[1].length;
        }

        if (decimals > 0) {
            number = Number(number.toFixed(decimals));
        }

        return number;
    }

    function renderControl(param, control, root, hidden) {
        var idBase = 'ppw_' + (param.id || Math.random().toString(36).slice(2));

        if (param.type === 'radio') {
            (param.options || []).forEach(function (option, index) {
                var optionValue = getOptionValue(param, option);
                var label = document.createElement('label');
                label.className = 'ppw-choice';
                var input = document.createElement('input');
                input.type = 'radio';
                input.name = idBase;
                input.value = optionValue;
                input.checked = String(param.value) === String(optionValue);
                input.addEventListener('change', function () {
                    param.value = optionValue;
                    resetHiddenBranches(param);
                    renderConfigurator(root, hidden);
                });
                label.appendChild(input);
                label.appendChild(document.createTextNode(' ' + (option.label || option.value)));
                control.appendChild(label);
            });
            return;
        }

        if (param.type === 'select') {
            var select = document.createElement('select');
            select.name = idBase;
            var empty = document.createElement('option');
            empty.value = '';
            empty.textContent = 'Выберите значение';
            select.appendChild(empty);
            (param.options || []).forEach(function (option) {
                var opt = document.createElement('option');
                opt.value = option.value;
                opt.textContent = option.label || option.value;
                opt.selected = String(param.value) === String(option.value);
                select.appendChild(opt);
            });
            select.addEventListener('change', function () {
                param.value = select.value === '' ? null : select.value;
                resetHiddenBranches(param);
                renderConfigurator(root, hidden);
            });
            control.appendChild(select);
            return;
        }

        if (param.type === 'checkbox') {
            var checkboxLabel = document.createElement('label');
            checkboxLabel.className = 'ppw-choice';
            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.name = idBase;
            checkbox.checked = param.value === true;
            checkbox.addEventListener('change', function () {
                param.value = checkbox.checked;
                resetHiddenBranches(param);
                renderConfigurator(root, hidden);
            });
            checkboxLabel.appendChild(checkbox);
            checkboxLabel.appendChild(document.createTextNode(' Да'));
            control.appendChild(checkboxLabel);
            return;
        }

        if (param.type === 'number') {
            var wrap = document.createElement('div');
            wrap.className = 'ppw-number-wrap';
            var input = document.createElement('input');
            input.type = 'number';
            input.name = idBase;
            if (param.min !== null && param.min !== undefined) input.min = param.min;
            if (param.max !== null && param.max !== undefined) input.max = param.max;
            if (param.step !== null && param.step !== undefined) input.step = param.step;
            input.value = param.value !== null && param.value !== undefined ? param.value : '';

            input.addEventListener('input', function () {
                param.value = input.value === '' ? null : Number(input.value);
                syncHidden(root, hidden);
            });

            input.addEventListener('blur', function () {
                var normalized = normalizeNumberValue(input.value, param.min, param.max, param.step);

                if (normalized === null) {
                    input.value = '';
                    param.value = null;
                } else {
                    input.value = normalized;
                    param.value = normalized;
                }

                syncHidden(root, hidden);
            });

            input.addEventListener('change', function () {
                var event;
                if (typeof Event === 'function') {
                    event = new Event('blur');
                } else {
                    event = document.createEvent('Event');
                    event.initEvent('blur', true, true);
                }
                input.dispatchEvent(event);
            });

            wrap.appendChild(input);
            if (param.unit) {
                var unit = document.createElement('span');
                unit.className = 'ppw-number-unit';
                unit.textContent = param.unit;
                wrap.appendChild(unit);
            }
            control.appendChild(wrap);
            return;
        }

        if (param.type === 'multi-select') {
            var selected = Array.isArray(param.value) ? param.value.map(String) : [];
            (param.options || []).forEach(function (option) {
                var multiLabel = document.createElement('label');
                multiLabel.className = 'ppw-choice';
                var input = document.createElement('input');
                input.type = 'checkbox';
                input.name = idBase + '[]';
                input.value = option.value;
                input.checked = selected.indexOf(String(option.value)) !== -1;
                input.addEventListener('change', function () {
                    var value = String(option.value);
                    selected = Array.isArray(param.value) ? param.value.map(String) : [];
                    if (input.checked && selected.indexOf(value) === -1) {
                        selected.push(value);
                    }
                    if (!input.checked) {
                        selected = selected.filter(function (item) { return item !== value; });
                    }
                    param.value = selected;
                    syncHidden(root, hidden);
                });
                multiLabel.appendChild(input);
                multiLabel.appendChild(document.createTextNode(' ' + (option.label || option.value)));
                control.appendChild(multiLabel);
            });
        }
    }

    /**
     * Older colour configurations may contain a copied option value (usually
     * "4+4") while their labels correctly say "4+0" and "4+4".  The browser
     * submits the option value, not its visible label, so both choices used to
     * produce 4+4. Keep the configured values for every other parameter, but
     * recover the well-known print colour notation from the colour label.
     */
    function getOptionValue(param, option) {
        var paramId = String(param && param.id ? param.id : '').toLowerCase();
        var isColour = paramId === 'color' || paramId === 'colour' || paramId === 'cvetnost';
        var match = isColour ? String(option && option.label ? option.label : '').match(/^\s*(\d+\s*\+\s*\d+)/) : null;

        if (match) {
            return match[1].replace(/\s/g, '');
        }

        return option ? option.value : '';
    }

    function renderVisibleChildren(param, container, root, hidden, level) {
        container.innerHTML = '';

        if (param.type === 'checkbox' && param.value === true && Array.isArray(param.children)) {
            renderParams(param.children, container, root, hidden, level);
        }

        if ((param.type === 'radio' || param.type === 'select') && param.value !== null && param.value !== undefined && param.value !== '') {
            if (Array.isArray(param.children)) {
                renderParams(param.children, container, root, hidden, level);
            }

            (param.options || []).forEach(function (option) {
                if (String(option.value) === String(param.value) && Array.isArray(option.children)) {
                    renderParams(option.children, container, root, hidden, level);
                }
            });
        }
    }

    function resetHiddenBranches(param) {
        if (param.type === 'checkbox' && param.value !== true && Array.isArray(param.children)) {
            resetParams(param.children);
        }

        if ((param.type === 'radio' || param.type === 'select') && Array.isArray(param.options)) {
            param.options.forEach(function (option) {
                if (String(option.value) !== String(param.value) && Array.isArray(option.children)) {
                    resetParams(option.children);
                }
            });
        }
    }

    function resetParams(params) {
        (params || []).forEach(function (param) {
            param.value = null;
            if (Array.isArray(param.children)) resetParams(param.children);
            if (Array.isArray(param.options)) {
                param.options.forEach(function (option) {
                    if (Array.isArray(option.children)) resetParams(option.children);
                });
            }
        });
    }

    function syncHidden(root, hidden) {
        if (!root || !hidden || !root.ppwState) {
            return;
        }
        hidden.value = JSON.stringify(pruneHidden(clone(root.ppwState)));
        syncLegacyInputs(root, root.ppwState.params || []);
    }

    // The price calculator used on the product page also keeps flat ppw_* fields.
    // Keep them in sync: integrations which serialize the whole form must not see
    // a stale default (notably ppw_color=4+4) beside the configurator JSON.
    function syncLegacyInputs(root, params) {
        var form = root.closest('form.cart');
        if (!form) return;

        (params || []).forEach(function (param) {
            if (param.id) {
                var name = 'ppw_' + String(param.id).replace(/[^a-z0-9_-]/gi, '');
                form.querySelectorAll('[name="' + name + '"]').forEach(function (input) {
                    if (input.type !== 'radio' && input.type !== 'checkbox' && input.name !== 'ppw_config') {
                        input.value = param.value === null || param.value === undefined ? '' : String(param.value);
                    }
                });
            }
            if (Array.isArray(param.children)) syncLegacyInputs(root, param.children);
            (param.options || []).forEach(function (option) {
                if (Array.isArray(option.children)) syncLegacyInputs(root, option.children);
            });
        });
    }

    function pruneHidden(config) {
        config.params = pruneParams(config.params || []);
        return config;
    }

    function pruneParams(params) {
        return (params || []).map(function (param) {
            var copy = clone(param);

            if (copy.type === 'checkbox') {
                if (copy.value === true && Array.isArray(copy.children)) {
                    copy.children = pruneParams(copy.children);
                } else {
                    copy.children = null;
                }
            }

            if ((copy.type === 'radio' || copy.type === 'select') && Array.isArray(copy.options)) {
                copy.options = copy.options.map(function (option) {
                    var optionCopy = clone(option);
                    if (String(optionCopy.value) === String(copy.value) && Array.isArray(optionCopy.children)) {
                        optionCopy.children = pruneParams(optionCopy.children);
                    } else if (Array.isArray(optionCopy.children)) {
                        optionCopy.children = null;
                    }
                    return optionCopy;
                });

                if (copy.value !== null && copy.value !== undefined && copy.value !== '' && Array.isArray(copy.children)) {
                    copy.children = pruneParams(copy.children);
                } else if (Array.isArray(copy.children)) {
                    copy.children = null;
                }
            }

            return copy;
        });
    }

    function hideNativeCommerceControls(form) {
        if (!form) {
            return;
        }

        var selectors = [
            '.quantity',
            '.quantity-wrapper',
            '.wd-quantity-overlap',
            '.single_add_to_cart_button',
            'button[name="add-to-cart"]',
            'input[name="add-to-cart"]'
        ];

        selectors.forEach(function (selector) {
            form.querySelectorAll(selector).forEach(function (element) {
                if (!element.classList.contains('ppw-submit-workflow')) {
                    element.style.setProperty('display', 'none', 'important');
                }
            });
        });
    }

    function initPrintWorkflowAjax() {
        initConfigurators();

        var forms = document.querySelectorAll('form.cart');
        if (!forms.length) {
            return;
        }

        forms.forEach(function (form) {
            var fileInput = form.querySelector('input[name="ppw_print_file"]');
            if (!fileInput || form.dataset.ppwAjaxReady === '1') {
                return;
            }

            form.dataset.ppwAjaxReady = '1';
            form.setAttribute('enctype', 'multipart/form-data');
            form.setAttribute('method', 'post');
            form.setAttribute('novalidate', 'novalidate');

            var quantityInput = form.querySelector('input.qty, input[name="quantity"]');
            if (quantityInput) {
                quantityInput.value = '1';
                quantityInput.setAttribute('value', '1');
                quantityInput.closest('.quantity') && (quantityInput.closest('.quantity').style.display = 'none');
            }

            var submitButtons = form.querySelectorAll('.ppw-submit-workflow, button.single_add_to_cart_button, button[name="add-to-cart"], input.single_add_to_cart_button, input[name="add-to-cart"]');
            var lastSubmitButton = null;

            form.classList.add('ppw-workflow-cart-form');
            hideNativeCommerceControls(form);

            submitButtons.forEach(function (button) {
                button.classList.remove('ajax_add_to_cart');
                if (button.tagName && button.tagName.toLowerCase() === 'button') {
                    button.setAttribute('type', 'button');
                }
                button.addEventListener('click', function (event) {
                    lastSubmitButton = button;

                    event.preventDefault();
                    event.stopPropagation();
                    if (typeof event.stopImmediatePropagation === 'function') {
                        event.stopImmediatePropagation();
                    }

                    if (form.dataset.ppwSubmitting === '1') {
                        return;
                    }

                    submitViaAjax(form, fileInput, lastSubmitButton);
                }, true);
            });

            form.addEventListener('submit', function (event) {
                event.preventDefault();
                event.stopPropagation();
                if (typeof event.stopImmediatePropagation === 'function') {
                    event.stopImmediatePropagation();
                }

                if (form.dataset.ppwSubmitting === '1') {
                    return;
                }

                var submitButton = event.submitter || lastSubmitButton || form.querySelector('button.single_add_to_cart_button[type="submit"], button[name="add-to-cart"], .single_add_to_cart_button');
                submitViaAjax(form, fileInput, submitButton);
            }, true);
        });
    }

    function submitViaAjax(form, fileInput, submitButton) {
        var configRoot = form.querySelector('[data-ppw-configurator]');
        var hiddenConfig = form.querySelector('input[name="ppw_config"]');

        if (configRoot && hiddenConfig) {
            var validationErrors = validateConfig(configRoot.ppwState ? configRoot.ppwState.params : []);
            if (validationErrors.length) {
                showNotice('error', validationErrors[0]);
                return;
            }
            syncHidden(configRoot, hiddenConfig);
        }

        var formData = new FormData(form);
        formData.set('quantity', '1');

        var calculatedPrice = getDisplayedProductPrice(form);
        if (calculatedPrice !== null) {
            formData.set('ppw_calculated_price', String(calculatedPrice));
        }

        var addToCartValue = '';
        var addToCartInput = form.querySelector('[name="add-to-cart"]');
        var productIdInput = form.querySelector('[name="product_id"]');

        if (submitButton && submitButton.name === 'add-to-cart' && submitButton.value) {
            addToCartValue = submitButton.value;
        } else if (addToCartInput && addToCartInput.value) {
            addToCartValue = addToCartInput.value;
        } else if (productIdInput && productIdInput.value) {
            addToCartValue = productIdInput.value;
        } else if (form.dataset.product_id) {
            addToCartValue = form.dataset.product_id;
        }

        if (addToCartValue) {
            formData.set('add-to-cart', addToCartValue);
            if (!formData.get('product_id')) {
                formData.set('product_id', addToCartValue);
            }
        }

        if (!fileInput.files || !fileInput.files.length) {
            showNotice('error', 'Пожалуйста, загрузите файл для печати.');
            return;
        }

        formData.set('action', 'ppw_ajax_add_to_cart');
        formData.set('security', (window.ppwAjax && window.ppwAjax.nonce) ? window.ppwAjax.nonce : '');
        form.dataset.ppwSubmitting = '1';

        if (submitButton) {
            submitButton.classList.add('loading');
            submitButton.disabled = true;
        }

        fetch((window.ppwAjax && window.ppwAjax.ajaxUrl) ? window.ppwAjax.ajaxUrl : '/wp-admin/admin-ajax.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(function (response) {
                return response.text().then(function (text) {
                    var data = null;
                    try {
                        data = JSON.parse(text);
                    } catch (e) {
                        throw new Error(text || 'Сервер вернул некорректный ответ.');
                    }
                    return data;
                });
            })
            .then(function (response) {
                if (!response || !response.success || !response.data) {
                    throw new Error((response && response.data && response.data.message) ? response.data.message : 'Не удалось добавить товар в корзину.');
                }

                if (response.data.fragments) {
                    Object.keys(response.data.fragments).forEach(function (selector) {
                        var element = document.querySelector(selector);
                        if (element) {
                            element.outerHTML = response.data.fragments[selector];
                        }
                    });
                }

                if (typeof jQuery !== 'undefined' && response.data.fragments) {
                    // WooCommerce/WoodMart handlers expect a jQuery object here
                    // and call .removeClass() on it.
                    var $button = submitButton ? jQuery(submitButton) : jQuery();
                    jQuery(document.body).trigger('added_to_cart', [response.data.fragments, response.data.cart_hash || '', $button]);
                }

                showNotice('success', response.data.message || 'Товар добавлен в корзину.');
            })
            .catch(function (error) {
                showNotice('error', error.message || 'Не удалось добавить товар в корзину.');
            })
            .finally(function () {
                form.dataset.ppwSubmitting = '0';
                if (submitButton) {
                    submitButton.classList.remove('loading');
                    submitButton.disabled = false;
                }
            });
    }

    function getDisplayedProductPrice(form) {
        var product = form.closest('.product') || document;
        var selectors = [
            '.woocommerce-variation-price .woocommerce-Price-amount',
            '.summary .price .woocommerce-Price-amount',
            '.summary .price .amount',
            '.price .woocommerce-Price-amount',
            '.price .amount'
        ];

        for (var i = 0; i < selectors.length; i += 1) {
            var amounts = product.querySelectorAll(selectors[i]);
            for (var j = amounts.length - 1; j >= 0; j -= 1) {
                if (amounts[j].offsetParent === null) {
                    continue;
                }
                var parsed = parsePrice(amounts[j].textContent);
                if (parsed !== null) {
                    return parsed;
                }
            }
        }

        return null;
    }

    function parsePrice(value) {
        var cleaned = String(value || '').replace(/[^0-9,.-]/g, '');
        if (!cleaned) {
            return null;
        }

        var decimalSeparator = window.wc_price_params && window.wc_price_params.decimal_separator
            ? window.wc_price_params.decimal_separator
            : (cleaned.lastIndexOf(',') > cleaned.lastIndexOf('.') ? ',' : '.');
        var thousandsSeparator = decimalSeparator === ',' ? '.' : ',';
        cleaned = cleaned.split(thousandsSeparator).join('');
        if (decimalSeparator !== '.') {
            cleaned = cleaned.replace(decimalSeparator, '.');
        }

        var price = Number(cleaned);
        return Number.isFinite(price) && price >= 0 ? price : null;
    }

    function validateConfig(params, errors) {
        errors = errors || [];
        (params || []).forEach(function (param) {
            var visible = true;
            var label = param.label || 'Параметр';
            var value = param.value;

            if (param.required) {
                if ((param.type === 'radio' || param.type === 'select' || param.type === 'number') && (value === null || value === undefined || value === '')) {
                    errors.push('Заполните поле «' + label + '».');
                }
                if (param.type === 'checkbox' && value === null) {
                    errors.push('Выберите значение для поля «' + label + '».');
                }
                if (param.type === 'multi-select' && (!Array.isArray(value) || !value.length)) {
                    errors.push('Выберите хотя бы один вариант в поле «' + label + '».');
                }
            }

            if (param.type === 'number' && value !== null && value !== undefined && value !== '') {
                var number = Number(value);
                if (Number.isNaN(number)) {
                    errors.push('Поле «' + label + '» должно быть числом.');
                }
                if (param.min !== null && param.min !== undefined && number < Number(param.min)) {
                    errors.push('Поле «' + label + '» меньше минимального значения.');
                }
                if (param.max !== null && param.max !== undefined && number > Number(param.max)) {
                    errors.push('Поле «' + label + '» больше максимального значения.');
                }
            }

            if (param.type === 'checkbox' && value === true && Array.isArray(param.children)) {
                validateConfig(param.children, errors);
            }

            if ((param.type === 'radio' || param.type === 'select') && value !== null && value !== undefined && value !== '') {
                if (Array.isArray(param.children)) {
                    validateConfig(param.children, errors);
                }
                (param.options || []).forEach(function (option) {
                    if (String(option.value) === String(value) && Array.isArray(option.children)) {
                        validateConfig(option.children, errors);
                    }
                });
            }
        });
        return errors;
    }

    function showNotice(type, message) {
        var wrapper = document.querySelector('.woocommerce-notices-wrapper');
        if (!wrapper) {
            wrapper = document.createElement('div');
            wrapper.className = 'woocommerce-notices-wrapper';

            var summary = document.querySelector('.summary.entry-summary, .product .summary, form.cart');
            if (summary && summary.parentNode) {
                summary.parentNode.insertBefore(wrapper, summary);
            } else {
                document.body.insertBefore(wrapper, document.body.firstChild);
            }
        }

        wrapper.innerHTML = '<div class="woocommerce-' + type + '">' + escapeHtml(message) + '</div>';
        wrapper.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function clone(value) {
        return JSON.parse(JSON.stringify(value));
    }

    function escapeHtml(string) {
        var div = document.createElement('div');
        div.textContent = string;
        return div.innerHTML;
    }

    function escapeAttr(string) {
        return String(string).replace(/[^a-z0-9_-]/gi, '');
    }

    document.addEventListener('DOMContentLoaded', function () {
        initPrintWorkflowAjax();

        var observer = new MutationObserver(function () {
            document.querySelectorAll('form.cart').forEach(function (form) {
                if (form.querySelector('input[name="ppw_print_file"]')) {
                    hideNativeCommerceControls(form);
                    initPrintWorkflowAjax();
                }
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });
    document.addEventListener('wc_variation_form', initPrintWorkflowAjax);
})();
