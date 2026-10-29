document.addEventListener("DOMContentLoaded", () => {
    const uploadArea = document.querySelector("[data-upload-url]");
    if (uploadArea instanceof HTMLElement) {
        initializeUpload(uploadArea);
    }

    document.querySelectorAll("[data-copy-code]").forEach((button) => {
        button.addEventListener("click", async () => {
            const code = document.querySelector("#new-access-code")?.textContent?.trim();
            if (!code) {
                return;
            }

            try {
                await navigator.clipboard.writeText(code);
                button.textContent = "Código copiado";
            } catch {
                button.textContent = "Copialo manualmente";
            }
        });
    });
});

function initializeUpload(area) {
    const input = area.querySelector("#photo-files");
    const countLabel = area.querySelector("[data-file-count]");
    const totalLabel = area.querySelector("[data-total-size]");
    const fileList = area.querySelector("[data-file-list]");
    const uploadButton = area.querySelector("[data-upload-button]");
    const progressContainer = area.querySelector("[data-progress-container]");
    const progressBar = area.querySelector("[data-progress-bar]");
    const progressPercent = area.querySelector("[data-progress-percent]");
    const progressMessage = area.querySelector("[data-progress-message]");
    const resultList = area.querySelector("[data-upload-results]");
    const csrfToken = area.querySelector("[data-csrf-token]")?.value;
    const maxFileBytes = Number(area.dataset.maxFileBytes);
    const maxFiles = Number(area.dataset.maxFiles);
    if (!input || !uploadButton || !countLabel || !fileList || !csrfToken) {
        return;
    }

    const directoryPicker = "webkitdirectory" in input;
    const pickerTitle = area.querySelector("[data-picker-title]");
    const pickerHelp = area.querySelector("[data-picker-help]");

    if (!directoryPicker && pickerTitle) {
        pickerTitle.textContent = "Elegir fotografías";
    }
    if (!directoryPicker && pickerHelp) {
        pickerHelp.textContent = "Este navegador permite seleccionar varios archivos a la vez.";
    }

    let selectedFiles = [];
    input.addEventListener("change", () => {
        selectedFiles = Array.from(input.files || []);
        countLabel.textContent = String(selectedFiles.length);
        totalLabel.textContent = formatBytes(selectedFiles.reduce((sum, file) => sum + file.size, 0));
        fileList.replaceChildren();

        selectedFiles.forEach((file) => {
            const item = document.createElement("li");
            const name = document.createElement("span");
            const size = document.createElement("small");
            name.textContent = file.webkitRelativePath || file.name;
            size.textContent = formatBytes(file.size);
            item.append(name, size);
            fileList.append(item);
        });

        uploadButton.disabled = selectedFiles.length === 0;
        resultList.replaceChildren();
        if (selectedFiles.length > maxFiles) {
            appendResult(
                resultList,
                "error",
                `El lote supera el máximo de ${maxFiles} archivos. Seleccioná una parte de la carpeta por vez.`
            );
            uploadButton.disabled = true;
        }
    });

    uploadButton.addEventListener("click", async () => {
        if (selectedFiles.length === 0 || selectedFiles.length > maxFiles) {
            return;
        }

        uploadButton.disabled = true;
        input.disabled = true;
        progressContainer.hidden = false;
        progressBar.value = 0;
        progressPercent.textContent = "0%";
        resultList.replaceChildren();

        try {
            const batch = await startBatch(area.dataset.startUrl, csrfToken, area.dataset.galleryId);
            const totalBytes = selectedFiles.reduce((sum, file) => sum + file.size, 0);
            let completedBytes = 0;
            let completedFiles = 0;
            let uploadedCount = 0;
            let duplicateCount = 0;
            let failedCount = 0;

            for (const file of selectedFiles) {
                const fileName = file.webkitRelativePath || file.name;
                if (file.size > Math.min(maxFileBytes, batch.max_file_bytes)) {
                    appendResult(
                        resultList,
                        "error",
                        `${fileName}: supera el límite de ${formatBytes(Math.min(maxFileBytes, batch.max_file_bytes))}.`
                    );
                    failedCount++;
                    completedFiles++;
                    completedBytes += file.size;
                    updateProgress(
                        progressBar,
                        progressPercent,
                        completedBytes,
                        totalBytes
                    );
                    progressMessage.textContent = `Procesados ${completedFiles} de ${selectedFiles.length} archivos`;
                    continue;
                }

                progressMessage.textContent = `Subiendo ${completedFiles + 1} de ${selectedFiles.length}: ${fileName}`;
                try {
                    const result = await uploadFile(
                        area.dataset.uploadUrl,
                        csrfToken,
                        area.dataset.galleryId,
                        batch.batch_token,
                        file,
                        (loaded) => updateProgress(
                            progressBar,
                            progressPercent,
                            completedBytes + loaded,
                            totalBytes
                        )
                    );

                    if (result.status === "uploaded") {
                        uploadedCount++;
                        appendResult(resultList, "success", `${fileName}: ${result.message}`);
                    } else if (result.status === "duplicate") {
                        duplicateCount++;
                        appendResult(resultList, "notice", `${fileName}: ${result.message}`);
                    } else {
                        failedCount++;
                        appendResult(resultList, "error", `${fileName}: ${result.message}`);
                    }
                } catch (error) {
                    failedCount++;
                    appendResult(
                        resultList,
                        "error",
                        `${fileName}: ${error instanceof Error ? error.message : "falló la solicitud."}`
                    );
                }

                completedFiles++;
                completedBytes += file.size;
                updateProgress(
                    progressBar,
                    progressPercent,
                    completedBytes,
                    totalBytes
                );
                if (completedFiles < selectedFiles.length) {
                    progressMessage.textContent =
                        `Procesados ${completedFiles} de ${selectedFiles.length} archivos`;
                }
            }

            progressMessage.textContent =
                `Carga terminada: ${uploadedCount} guardadas, ${duplicateCount} duplicadas, ${failedCount} con error.`;
            if (uploadedCount > 0) {
                const refresh = document.createElement("a");
                refresh.className = "refresh-gallery-link";
                refresh.href = window.location.href;
                refresh.textContent = "Actualizar la galería";
                resultList.append(refresh);
            }
        } catch (error) {
            appendResult(
                resultList,
                "error",
                error instanceof Error ? error.message : "No se pudo iniciar el lote."
            );
            progressMessage.textContent = "No se pudo iniciar la carga.";
        } finally {
            input.disabled = false;
            uploadButton.disabled = selectedFiles.length === 0 || selectedFiles.length > maxFiles;
        }
    });
}

async function startBatch(url, csrfToken, galleryId) {
    const body = new URLSearchParams({
        csrf_token: csrfToken,
        gallery_id: galleryId,
    });
    const response = await fetch(url, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body,
        credentials: "same-origin",
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok || !result.batch_token) {
        throw new Error(result.message || "No se pudo iniciar el lote de carga.");
    }
    return result;
}

function uploadFile(url, csrfToken, galleryId, batchToken, file, onProgress) {
    return new Promise((resolve, reject) => {
        const request = new XMLHttpRequest();
        const body = new FormData();
        body.append("csrf_token", csrfToken);
        body.append("gallery_id", galleryId);
        body.append("batch_token", batchToken);
        body.append("photo", file, file.name);

        request.open("POST", url);
        request.withCredentials = true;
        request.upload.addEventListener("progress", (event) => {
            if (event.lengthComputable) {
                onProgress(Math.min(file.size, Math.round((event.loaded / event.total) * file.size)));
            }
        });
        request.addEventListener("load", () => {
            let result;
            try {
                result = JSON.parse(request.responseText);
            } catch {
                reject(new Error("PHP rechazó la solicitud. Revisá upload_max_filesize y post_max_size."));
                return;
            }

            if (request.status < 200 || request.status >= 300) {
                reject(new Error(result.message || "El servidor rechazó este archivo."));
                return;
            }
            resolve(result);
        });
        request.addEventListener("error", () => reject(new Error("Error de red durante la carga.")));
        request.addEventListener("abort", () => reject(new Error("La carga fue interrumpida.")));
        request.send(body);
    });
}

function updateProgress(bar, percentLabel, loadedBytes, totalBytes) {
    const percent = totalBytes > 0
        ? Math.min(100, Math.round((loadedBytes / totalBytes) * 100))
        : Math.round((completed / count) * 100);
    bar.value = percent;
    bar.textContent = `${percent}%`;
    percentLabel.textContent = `${percent}%`;
}

function appendResult(list, type, message) {
    const item = document.createElement("li");
    item.className = `upload-result result-${type}`;
    item.textContent = message;
    list.append(item);
}

function formatBytes(bytes) {
    if (bytes < 1024) {
        return `${bytes} B`;
    }
    const units = ["KB", "MB", "GB"];
    let size = bytes / 1024;
    let unit = 0;
    while (size >= 1024 && unit < units.length - 1) {
        size /= 1024;
        unit++;
    }
    return `${size.toFixed(size < 10 ? 1 : 0)} ${units[unit]}`;
}
