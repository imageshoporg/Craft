/**
 * ImageShop plugin for Craft CMS
 *
 * ImageShopField Field JS
 *
 * @author    WebDNA
 * @copyright Copyright (c) 2022 WebDNA
 * @link      https://webdna.co.uk
 * @package   ImageShop
 * @since     2.0.0ImageShop
 */

(function ($) {

    Craft.ImageShopDAMField = Garnish.Base.extend(
        {
            $container: null,
            $trigger: null,
            $hiddenInput: null,
            $previewInput: null,
            $removeButton: null,
            $url: null,
            $popupWindow: null,
            $open: false,
            $name: null,
            $listElement: null,

            init: function (options) {

                this.$container = $('[data-id="' + options.namespace + 'imageshop"]');
                this.$listElement = this.$container.find('[data-imageshop-list]');

                this.$url = options.url;
                this.$name = options.name,
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

                            let merged = [...existingData, ...newData];
                            let unique = [];
                            let codes = new Set();
                            merged.forEach((img) => {
                                if (!codes.has(img.code)) {
                                    unique.push(img);
                                    codes.add(img.code);
                                }
                            });

                            this.$hiddenInput.val(JSON.stringify(unique));
                            this.updatePreview(JSON.stringify(unique));

                        }
                    }
                }.bind(this), false);

                this.initDragAndDrop();
            },

            initDragAndDrop: function () {
                const self = this;

                Sortable.create(this.$previewInput[0], {
                    animation: 150,
                    handle: '.imageshop-img-container',
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

            removeSelection: function (event) {
                let code = event.currentTarget.dataset.imgCode;
                let input = this.$hiddenInput.val();
                this.$hiddenInput.val(null);

                try {
                    let json = JSON.parse(input);
                    if (Array.isArray(json)) {
                        let filtered = json.filter((image) => ('code' in image) && (image.code != code));
                        this.$hiddenInput.val(JSON.stringify(filtered));
                    }

                } catch (error) {

                }
                $(event.currentTarget).parents().eq(1).remove();

            },

            removePreview: function () {
                this.$previewInput.empty();
            },


            showPopup: function (ev) {
                ev.preventDefault();
                this.$open = true;

                // Sensible defaults
                var width = 950;
                var height = 650;

                var leftPosition = (screen.width) ? (screen.width - width) / 2 : 0;
                var topPosition = (screen.height) ? (screen.height - height) / 2 : 0;
                var settings = 'height=' + height + ',width=' + width + ',top=' + topPosition + ',left=' + leftPosition + ',resizable';
                this.$popupWindow = window.open(this.$url, "imageshop", settings);
            },

            updatePreview: function (data) {
                var json = JSON.parse(data);
                console.log('json data', data);

                //  have we returned multiple?
                this.removePreview();
                if (!Array.isArray(json)) {
                    json = [json];
                }

                // update list
                var controllerUrl = Craft.baseCpUrl + '&p=actions/imageshop-dam/content/get-image-list';
                var listElement = this.$listElement;
                var object = this;

                $.ajax({
                    url: controllerUrl,
                    method: "POST",
                    data: {
                        jsonData: json,
                    },
                    dataType: "json",
                    headers: {
                        "Accept": "application/json"
                    },
                    success: function (data) {
                        listElement.html(data.result);
                        listElement.find('.imageshop-remove').each(function(){
                            object.addListener($(this), "click", 'removeSelection');
                        });
                    }
                });

            }
        });

})(jQuery);