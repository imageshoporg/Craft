/**
 * Imageshop plugin for Craft CMS
 *
 * ImageShopField Field JS
 *
 * @author    Imageshop
 * @copyright Copyright (c) 2022 Imageshop
 * @link      https://www.imageshop.org
 * @package   Imageshop
 * @since     2.0.0
 */

(function ($) {

    Craft.ImageShopDAMField = Garnish.Base.extend(
        {
            $container: null,
            $trigger: null,
            $hiddenInput: null,
            $previewInput: null,
            $removeButton: null,
            $pickerOptions: null,
            $popupWindow: null,
            $open: false,
            $name: null,
            $listElement: null,
            $allowMultiple: true,

            init: function (options) {

                this.$container = $('[data-id="' + options.namespace + 'imageshop"]');
                this.$listElement = this.$container.find('[data-imageshop-list]');

                this.$pickerOptions = options.pickerOptions || {};
                this.$name = options.name;
                this.$allowMultiple = options.allowMultiple;
                this.$trigger = this.$container.find(".imageshop-trigger");
                this.$hiddenInput = this.$container.find(".imageshop-value");
                this.$previewInput = this.$container.find(".imageshop-preview");
                let self = this;

                this.$previewInput.find(".imageshop-remove").each(function (index) {
                    self.addListener(this, 'click', 'removeSelection');
                });

                this.addListener(this.$trigger, "click", "showPopup");

                window.addEventListener("message", function (event) {
                    if (event.origin == 'https://client.imageshop.no') {
                        if (this.$open) {
                            this.$open = false;
                            this.$popupWindow.close();

                            let existingData = [];
                            let newData = [];

                            try {
                                const inputVal = this.$hiddenInput.val();
                                if (inputVal) {
                                    existingData = JSON.parse(inputVal);
                                    if (!Array.isArray(existingData)) {
                                        existingData = [existingData];
                                    }
                                }
                            } catch (e) {
                                existingData = [];
                            }

                            try {
                                newData = JSON.parse(event.data);
                                if (!Array.isArray(newData)) {
                                    newData = [newData];
                                }
                            } catch (e) {
                                newData = [];
                            }

                            let result;
                            if (!this.$allowMultiple) {
                                result = newData.slice(0, 1);
                            } else {
                                let merged = [...existingData, ...newData];
                                let unique = [];
                                let codes = new Set();
                                merged.forEach((img) => {
                                    if (!codes.has(img.code)) {
                                        unique.push(img);
                                        codes.add(img.code);
                                    }
                                });
                                result = unique;
                            }

                            this.$hiddenInput.val(JSON.stringify(result));
                            this.updatePreview(JSON.stringify(result));

                        }
                    }
                }.bind(this), false);

                this.initDragAndDrop();
                this.updateAltText();
            },

            initDragAndDrop: function () {
                const self = this;

                Sortable.create(this.$previewInput[0], {
                    animation: 150,
                    handle: '.imageshop-img-container .move',
                    onEnd: function () {
                        self.syncInputWithPreview();
                    }
                });
            },

            syncInputWithPreview: function () {
                let currentData;
                try {
                    currentData = JSON.parse(this.$hiddenInput.val());
                    if (!Array.isArray(currentData)) {
                        currentData = [currentData];
                    }
                } catch (e) {
                    currentData = [];
                }

                const sortedData = [];

                this.$previewInput.find('.imageshop-img-container').each(function () {
                    const code = $(this).find('.imageshop-remove').data('img-code');
                    const match = currentData.find(item => item.code === code);
                    if (match) {
                        sortedData.push(match);
                    }
                });

                this.$hiddenInput.val(JSON.stringify(sortedData));
            },

            updateAltText: function () {
                var obj = this;

                this.$container.find('[data-alt-text], [data-description-text]').on('input', function () {
                    let currentData;
                    try {
                        currentData = JSON.parse(obj.$hiddenInput.val());
                        if (!Array.isArray(currentData)) {
                            currentData = [currentData];
                        }
                    } catch (e) {
                        currentData = [];
                    }

                    const isAltInput = $(this).is('[data-alt-text]');
                    const isDescriptionInput = $(this).is('[data-description-text]');

                    const updatedData = [];
                    obj.$previewInput.find('.imageshop-img-container').each(function () {
                        const code = $(this).find('.imageshop-remove').data('img-code');
                        const match = currentData.find(item => item.code === code);

                        if (match) {
                            var language = obj.$container.attr('data-current-language');
                            let textBlock = match.text[language];

                            if (!textBlock) {
                                textBlock = {
                                    "title": null,
                                    "description": null,
                                    "rights": null,
                                    "credits": null,
                                    "tags": null,
                                    "altText": null,
                                    "categories": null,
                                    "documentinfo": null
                                };
                                match.text[language] = textBlock;
                            }

                            if (isAltInput) {
                                textBlock.altText = $(this).find('[data-alt-text]').val();
                            }

                            if (isDescriptionInput) {
                                textBlock.description = $(this).find('[data-description-text]').val();
                            }

                            updatedData.push(match);
                        }
                    });

                    obj.$hiddenInput.val(JSON.stringify(updatedData));
                });

                this.$container.find('[data-imageshop-trigger-settings]').on('click', function () {
                    $(this)
                        .closest('[data-imageshop-image-wrapper]')
                        .find('[data-imageshop-alt-wrapper]')
                        .toggle();
                });
            },

            removeSelection: function (event) {
                let code = event.currentTarget.dataset.imgCode;
                let input = this.$hiddenInput.val();
                this.$hiddenInput.val(null);

                try {
                    let json = JSON.parse(input);
                    if (Array.isArray(json)) {
                        let filtered = json.filter((image) => ('code' in image) && (image.code !== code));
                        this.$hiddenInput.val(JSON.stringify(filtered));
                    }

                } catch (error) {

                }
                $(event.currentTarget).closest('.imageshop-img-container').remove();

            },

            removePreview: function () {
                this.$previewInput.empty();
            },


            showPopup: function (ev) {
                ev.preventDefault();

                var self = this;
                var width = 950;
                var height = 650;
                var leftPosition = (screen.width) ? (screen.width - width) / 2 : 0;
                var topPosition = (screen.height) ? (screen.height - height) / 2 : 0;
                var popupSettings = 'height=' + height + ',width=' + width + ',top=' + topPosition + ',left=' + leftPosition + ',resizable';

                $.ajax({
                    url: Craft.getActionUrl('imageshop-dam/picker/get-url'),
                    method: "POST",
                    data: self.$pickerOptions,
                    dataType: "json",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-Token": Craft.csrfTokenValue
                    },
                    success: function (resp) {
                        if (!resp || !resp.url) {
                            var msg = (resp && resp.error) ? resp.error : Craft.t('imageshop-dam', 'Could not open ImageShop picker.');
                            Craft.cp.displayError(msg);
                            return;
                        }
                        self.$open = true;
                        self.$popupWindow = window.open(resp.url, "imageshop", popupSettings);
                    },
                    error: function (xhr) {
                        var msg = (xhr.responseJSON && xhr.responseJSON.error)
                            ? xhr.responseJSON.error
                            : Craft.t('imageshop-dam', 'Could not open ImageShop picker.');
                        Craft.cp.displayError(msg);
                    }
                });
            },

            refreshAndStore: function (result, newData) {
                var self = this;
                var newDocIds = newData
                    .filter(function (img) { return img.documentId; })
                    .map(function (img) { return img.documentId; });

                if (newDocIds.length === 0) {
                    self.$hiddenInput.val(JSON.stringify(result));
                    self.updatePreview(JSON.stringify(result));
                    return;
                }

                // Collect all languages present in the image text blocks
                var languages = [];
                newData.forEach(function (img) {
                    if (img.text) {
                        Object.keys(img.text).forEach(function (lang) {
                            if (languages.indexOf(lang) === -1) {
                                languages.push(lang);
                            }
                        });
                    }
                });

                var fieldMap = {
                    'AltText': 'altText',
                    'Description': 'description',
                    'Name': 'title',
                    'Credits': 'credits',
                    'Rights': 'rights',
                    'Tags': 'tags'
                };

                $.ajax({
                    url: Craft.getActionUrl('imageshop-dam/content/refresh-metadata'),
                    method: "POST",
                    data: {
                        documentIds: newDocIds,
                        languages: languages,
                    },
                    dataType: "json",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-Token": Craft.csrfTokenValue
                    },
                    success: function (response) {
                        var freshData = response.result || {};

                        result.forEach(function (img) {
                            var docFresh = freshData[img.documentId];
                            if (!docFresh || !img.text) return;

                            Object.keys(img.text).forEach(function (lang) {
                                var apiDoc = docFresh[lang];
                                if (!apiDoc || !img.text[lang]) return;

                                Object.keys(fieldMap).forEach(function (apiKey) {
                                    var pickerKey = fieldMap[apiKey];
                                    if (apiKey in apiDoc) {
                                        img.text[lang][pickerKey] = apiDoc[apiKey];
                                    }
                                });
                            });
                        });

                        self.$hiddenInput.val(JSON.stringify(result));
                        self.updatePreview(JSON.stringify(result));
                    },
                    error: function () {
                        // Fallback: store without refresh
                        self.$hiddenInput.val(JSON.stringify(result));
                        self.updatePreview(JSON.stringify(result));
                    }
                });
            },

            updatePreview: function (data) {
                var json = JSON.parse(data);

                //  have we returned multiple?
                this.removePreview();
                if (!Array.isArray(json)) {
                    json = [json];
                }

                // update list
                var controllerUrl = Craft.getActionUrl('imageshop-dam/content/get-image-list');
                var listElement = this.$listElement;
                var object = this;
                var language = this.$container.attr('data-current-language');

                $.ajax({
                    url: controllerUrl,
                    method: "POST",
                    data: {
                        jsonData: json,
                        language: language,
                    },
                    dataType: "json",
                    headers: {
                        "Accept": "application/json",
                        "X-CSRF-Token": Craft.csrfTokenValue
                    },
                    success: function (data) {
                        listElement.html(data.result);
                        listElement.find('.imageshop-remove').each(function(){
                            object.addListener($(this), "click", 'removeSelection');
                        });
                        object.updateAltText();
                    }
                });

            }
        });

})(jQuery);
