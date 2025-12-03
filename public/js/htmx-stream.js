(function() {
    htmx.defineExtension("stream", {
        onEvent: function (name, evt) {
            if (name === "htmx:beforeRequest") {
                let element = evt.detail.elt;

                if (evt.detail.requestConfig.target) {
                    element['__target'] = evt.detail.requestConfig.target;
                    element = evt.detail.requestConfig.target;
                }

                let xhr = evt.detail.xhr;

                let lastLength = 0;
                xhr.addEventListener('readystatechange', function () {
                    const is_chunked = xhr.getResponseHeader("Content-Type").search("text/stream") !== -1;
                    if (!is_chunked) {
                        return;
                    }
                    if (xhr.readyState === 2 || xhr.readyState === 3) {
                        let newText = xhr.responseText.substring(lastLength);
                        let parts = newText.split("\n§STREAM-STOP§\n");

                        parts.forEach(function(part) {
                            if (part.startsWith("streamId:")) {
                                const newlineIndex = part.indexOf("\n");
                                if (newlineIndex !== -1) {
                                    const streamId = part.substring(9, newlineIndex).trim();
                                    const content = part.substring(newlineIndex + 1);

                                    const targetElement = document.getElementById(streamId);
                                    if (targetElement) {
                                        targetElement.innerHTML = content;
                                    }
                                    else {
                                        element.innerHTML += content;
                                    }
                                }
                            } else if (part.length > 0) {
                                element.innerHTML += part;
                            }
                        });

                        element['__streamedChars'] = lastLength;
                        lastLength = xhr.responseText.length;
                    }
                });
            }
            return true;
        },
        transformResponse: function (text, xhr, elt) {
            const is_chunked = xhr.getResponseHeader("Content-Type").search("text/stream") !== -1;
            if (!is_chunked) {
                return text;
            }

            return '';
        }
    });
})();