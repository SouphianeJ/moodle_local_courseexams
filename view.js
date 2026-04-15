(function() {
    const bootstrap = () => {
        const configNode = document.getElementById('local-courseexams-config');
        const config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
        const strings = config.strings || {};
        const form = document.getElementById('local-courseexams-form');
        const input = document.getElementById('local-courseexams-courseid');
        const hiddenInput = document.getElementById('local-courseexams-courseid-value');
        const searchResultsNode = document.getElementById('local-courseexams-search-results');
        const statusNode = document.getElementById('local-courseexams-status');
        const summaryNode = document.getElementById('local-courseexams-summary');
        const listNode = document.getElementById('local-courseexams-list');

        if (!form || !input || !hiddenInput || !searchResultsNode || !statusNode || !summaryNode || !listNode) {
            return;
        }

        let currentCourseId = Number(config.initialcourseid || 0);
        let timer = null;
        let searchTimer = null;
        let selectedLabel = currentCourseId > 0 ? input.value : '';
        let archivedLoadedFor = 0;

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const renderState = (type, message) => {
            statusNode.innerHTML = message ? `<div class="local-courseexams-${type}">${escapeHtml(message)}</div>` : '';
        };

        const hideSearchResults = () => {
            searchResultsNode.hidden = true;
            searchResultsNode.innerHTML = '';
        };

        const renderSearchResults = (courses) => {
            if (!courses.length) {
                searchResultsNode.innerHTML = `<div class="local-courseexams-search-empty">${escapeHtml(strings.searchnoresults)}</div>`;
                searchResultsNode.hidden = false;
                return;
            }

            searchResultsNode.innerHTML = `
                <div class="local-courseexams-search-title">${escapeHtml(strings.searchresults)}</div>
                ${courses.map((course) => `
                    <button class="local-courseexams-search-option" type="button" data-course-id="${escapeHtml(course.id)}" data-course-label="${escapeHtml(course.fullname)}">
                        ${escapeHtml(course.fullname)}
                    </button>
                `).join('')}
            `;
            searchResultsNode.hidden = false;
        };

        const fetchJson = async(bodyParams) => {
            const response = await fetch(config.ajaxurl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                },
                body: new URLSearchParams({
                    sesskey: config.sesskey,
                    ...bodyParams,
                }),
            });
            const payload = await response.json();

            if (!response.ok || payload.status !== 'ok') {
                throw new Error(payload.message || 'Erreur');
            }

            return payload.data;
        };

        const groupMeta = (exam) => {
            if (exam.type === 'quiz') {
                return {
                    schedule: exam.meta.slice(0, 2),
                    settings: exam.meta.slice(2, 5),
                    links: exam.meta.slice(5),
                };
            }

            return {
                schedule: exam.meta.slice(0, 4),
                settings: exam.meta.slice(4, 7),
                links: exam.meta.slice(7),
            };
        };

        const renderMetaLines = (items) => items.map((item) => `
            <div class="local-courseexams-cell-line">
                <span class="local-courseexams-cell-label">${escapeHtml(item.label)}</span>
                <span class="local-courseexams-cell-value">${item.linkurl
                    ? `<a class="local-courseexams-meta-link" href="${escapeHtml(item.linkurl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(item.value)}</a>`
                    : escapeHtml(item.value)}</span>
            </div>
        `).join('');

        const renderRows = (rows, columns) => {
            if (!rows.length) {
                return `<p class="local-courseexams-muted">${escapeHtml(strings.empty || '')}</p>`;
            }

            const head = columns.map((column) => `<th>${escapeHtml(column.label)}</th>`).join('');
            const body = rows.map((row) => `
                <tr>${columns.map((column) => `<td>${escapeHtml(row[column.key] ?? '')}</td>`).join('')}</tr>
            `).join('');

            return `<table class="local-courseexams-table"><thead><tr>${head}</tr></thead><tbody>${body}</tbody></table>`;
        };

        const renderExamDetail = (exam) => {
            const questionDetails = exam.type === 'quiz'
                ? `
                    <details class="local-courseexams-details">
                        <summary>${escapeHtml(strings.questions)} (${exam.questions.length})</summary>
                        <div class="local-courseexams-details-content">
                            ${renderRows(exam.questions, [
                                { key: 'slot', label: strings.slot },
                                { key: 'displayednumber', label: strings.number },
                                { key: 'name', label: strings.question },
                                { key: 'qtype', label: strings.type },
                                { key: 'maxmark', label: strings.maxmark },
                                { key: 'page', label: strings.page },
                            ])}
                        </div>
                    </details>
                `
                : '';

            const overrideBlocks = exam.overrides.details.map((block) => `
                <details class="local-courseexams-details">
                    <summary>${escapeHtml(block.title)} (${block.count})</summary>
                    <div class="local-courseexams-details-content">
                        ${renderRows(block.items, block.columns)}
                    </div>
                </details>
            `).join('');

            return `
                <div class="local-courseexams-subpanel">
                    <div class="local-courseexams-chip-row local-courseexams-chip-row-tight">
                        <span class="local-courseexams-chip">${escapeHtml(strings.overrides)}: ${escapeHtml(exam.overrides.total)}</span>
                        ${exam.overrides.summary.map((item) => `<span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(item)}</span>`).join('')}
                    </div>
                    ${overrideBlocks}
                    ${questionDetails}
                </div>
            `;
        };

        const renderExamRow = (exam, index, prefix) => {
            const groups = groupMeta(exam);
            const detailsId = `${prefix}-detail-${index}`;
            const title = exam.editurl
                ? `<a class="local-courseexams-card-title-link" href="${escapeHtml(exam.editurl)}" target="_blank" rel="noopener noreferrer">${escapeHtml(exam.name)}</a>`
                : escapeHtml(exam.name);

            return `
                <tr class="local-courseexams-exam-row">
                    <td class="local-courseexams-row-toggle-cell">
                        <button class="local-courseexams-row-toggle" type="button" aria-expanded="false" aria-controls="${escapeHtml(detailsId)}">${escapeHtml(strings.expandrow)}</button>
                    </td>
                    <td>
                        <div class="local-courseexams-exam-name">${title}</div>
                        <div class="local-courseexams-chip-row">
                            <span class="local-courseexams-chip">${escapeHtml(exam.type_label)}</span>
                            <span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(exam.section.label)}</span>
                            <span class="local-courseexams-chip ${exam.visible ? '' : 'hidden'}">${escapeHtml(exam.visible_label)}</span>
                        </div>
                    </td>
                    <td>${renderMetaLines(groups.schedule)}</td>
                    <td>${renderMetaLines(groups.settings)}</td>
                    <td>${renderMetaLines(groups.links)}</td>
                </tr>
                <tr id="${escapeHtml(detailsId)}" class="local-courseexams-detail-row" hidden>
                    <td colspan="5">${renderExamDetail(exam)}</td>
                </tr>
            `;
        };

        const renderExamTable = (title, exams, emptyMessage, prefix) => `
            <section class="local-courseexams-table-block">
                <div class="local-courseexams-table-header">
                    <h3>${escapeHtml(title)}</h3>
                    <span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(exams.length)}</span>
                </div>
                ${exams.length ? `
                    <div class="local-courseexams-table-wrap">
                        <table class="local-courseexams-table-grid">
                            <thead>
                                <tr>
                                    <th></th>
                                    <th>${escapeHtml(strings.exams || 'Examens')}</th>
                                    <th>${escapeHtml(strings.schedule)}</th>
                                    <th>${escapeHtml(strings.settings)}</th>
                                    <th>${escapeHtml(strings.links)}</th>
                                </tr>
                            </thead>
                            <tbody>${exams.map((exam, index) => renderExamRow(exam, index, prefix)).join('')}</tbody>
                        </table>
                    </div>
                ` : `<div class="local-courseexams-note">${escapeHtml(emptyMessage)}</div>`}
            </section>
        `;

        const renderArchivedPlaceholder = (summary) => `
            <details id="local-courseexams-archived-toggle" class="local-courseexams-archive-toggle">
                <summary>${escapeHtml(strings.showarchivedexams)} (${escapeHtml(summary.pastorhiddencount)})</summary>
                <div id="local-courseexams-archived-content" class="local-courseexams-archive-content"></div>
            </details>
        `;

        const attachRowToggles = (scopeNode) => {
            scopeNode.querySelectorAll('.local-courseexams-row-toggle').forEach((button) => {
                button.addEventListener('click', () => {
                    const detailRow = document.getElementById(button.getAttribute('aria-controls'));
                    if (!detailRow) {
                        return;
                    }

                    const expanded = button.getAttribute('aria-expanded') === 'true';
                    button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
                    detailRow.hidden = expanded;
                });
            });
        };

        const attachArchivedLoader = () => {
            const archivedToggle = document.getElementById('local-courseexams-archived-toggle');
            const archivedContent = document.getElementById('local-courseexams-archived-content');

            if (!archivedToggle || !archivedContent) {
                return;
            }

            archivedToggle.addEventListener('toggle', async() => {
                if (!archivedToggle.open || archivedLoadedFor === currentCourseId) {
                    return;
                }

                archivedContent.innerHTML = `<div class="local-courseexams-note">${escapeHtml(strings.loading || '')}</div>`;

                try {
                    const data = await fetchJson({
                        action: 'archived_exams',
                        courseid: String(currentCourseId),
                    });
                    archivedContent.innerHTML = renderExamTable(
                        strings.archivedexams,
                        data.archivedexams || [],
                        strings.empty || '',
                        'archived'
                    );
                    attachRowToggles(archivedContent);
                    archivedLoadedFor = currentCourseId;
                } catch (error) {
                    archivedContent.innerHTML = `<div class="local-courseexams-error">${escapeHtml(error.message || 'Erreur')}</div>`;
                }
            });
        };

        const renderSummary = (data) => {
            summaryNode.innerHTML = `
                <section class="local-courseexams-summary-bar">
                    <div class="local-courseexams-summary-course">
                        <span class="local-courseexams-summary-label">${escapeHtml(strings.coursefullname)}</span>
                        <strong class="local-courseexams-summary-course-name">${escapeHtml(data.course.fullname)}</strong>
                    </div>
                    <div class="local-courseexams-summary-metrics">
                        <article class="local-courseexams-summary-pill">
                            <span class="local-courseexams-summary-label">${escapeHtml(strings.upcomingexams)}</span>
                            <strong class="local-courseexams-summary-value">${escapeHtml(data.summary.upcomingcount)}</strong>
                        </article>
                        <article class="local-courseexams-summary-pill local-courseexams-summary-pill-muted">
                            <span class="local-courseexams-summary-label">${escapeHtml(strings.pastorhiddenexams)}</span>
                            <strong class="local-courseexams-summary-value">${escapeHtml(data.summary.pastorhiddencount)}</strong>
                        </article>
                    </div>
                </section>
            `;
        };

        const renderData = (data) => {
            renderState('note', `${strings.refreshedat} ${data.generated.label}`);
            renderSummary(data);
            listNode.innerHTML = `
                ${renderExamTable(strings.upcomingexams, data.upcomingexams || [], strings.empty || '', 'upcoming')}
                ${renderArchivedPlaceholder(data.summary)}
            `;
            attachRowToggles(listNode);
            attachArchivedLoader();
        };

        const resolveCourseId = () => {
            const selectedId = Number(hiddenInput.value || 0);
            if (selectedId > 0 && input.value.trim() === selectedLabel) {
                return selectedId;
            }

            const rawValue = input.value.trim();
            if (/^\d+$/.test(rawValue)) {
                return Number(rawValue);
            }

            return 0;
        };

        const loadCourse = async(courseId) => {
            if (!courseId || Number.isNaN(courseId) || courseId < 1) {
                renderState('error', strings.invalidcourseid || '');
                summaryNode.innerHTML = '';
                listNode.innerHTML = '';
                return;
            }

            renderState('note', strings.loading || '');
            archivedLoadedFor = 0;

            try {
                const data = await fetchJson({
                    action: 'course_overview',
                    courseid: String(courseId),
                });
                currentCourseId = courseId;
                hiddenInput.value = String(courseId);
                input.value = data.course.fullname;
                selectedLabel = data.course.fullname;
                hideSearchResults();
                renderData(data);
            } catch (error) {
                summaryNode.innerHTML = '';
                listNode.innerHTML = '';
                renderState('error', error.message || 'Erreur');
            }
        };

        const restartTimer = () => {
            if (timer) {
                window.clearInterval(timer);
            }
            if (currentCourseId > 0) {
                timer = window.setInterval(() => loadCourse(currentCourseId), Number(config.pollintervalms || 20000));
            }
        };

        const runSearch = async() => {
            const query = input.value.trim();

            if (query === selectedLabel && Number(hiddenInput.value || 0) > 0) {
                hideSearchResults();
                return;
            }

            hiddenInput.value = '';

            if (query.length < 3) {
                hideSearchResults();
                return;
            }

            try {
                const data = await fetchJson({
                    action: 'search_courses',
                    query,
                });
                renderSearchResults(data.courses || []);
            } catch (error) {
                searchResultsNode.innerHTML = `<div class="local-courseexams-search-empty">${escapeHtml(error.message || 'Erreur')}</div>`;
                searchResultsNode.hidden = false;
            }
        };

        input.addEventListener('input', () => {
            if (input.value.trim() !== selectedLabel) {
                hiddenInput.value = '';
            }

            if (searchTimer) {
                window.clearTimeout(searchTimer);
            }
            searchTimer = window.setTimeout(runSearch, 250);
        });

        input.addEventListener('focus', () => {
            if (input.value.trim().length >= 3 && input.value.trim() !== selectedLabel) {
                runSearch();
            }
        });

        document.addEventListener('click', (event) => {
            const option = event.target.closest('.local-courseexams-search-option');
            if (option) {
                hiddenInput.value = option.getAttribute('data-course-id') || '';
                input.value = option.getAttribute('data-course-label') || '';
                selectedLabel = input.value;
                hideSearchResults();
                return;
            }

            if (!event.target.closest('.local-courseexams-form')) {
                hideSearchResults();
            }
        });

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            const resolvedCourseId = resolveCourseId();
            const url = new URL(window.location.href);

            if (resolvedCourseId > 0) {
                url.searchParams.set('courseid', String(resolvedCourseId));
            } else {
                url.searchParams.delete('courseid');
            }

            window.history.replaceState({}, '', url);
            currentCourseId = resolvedCourseId;
            loadCourse(resolvedCourseId);
            restartTimer();
        });

        if (currentCourseId > 0) {
            loadCourse(currentCourseId);
            restartTimer();
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bootstrap, { once: true });
    } else {
        bootstrap();
    }
})();
