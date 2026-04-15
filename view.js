(function() {
    const bootstrap = () => {
        const configNode = document.getElementById('local-courseexams-config');
        const config = configNode ? JSON.parse(configNode.textContent || '{}') : {};
        const strings = config.strings || {};
        const form = document.getElementById('local-courseexams-form');
        const input = document.getElementById('local-courseexams-courseid');
        const statusNode = document.getElementById('local-courseexams-status');
        const summaryNode = document.getElementById('local-courseexams-summary');
        const listNode = document.getElementById('local-courseexams-list');

        if (!form || !input || !statusNode || !summaryNode || !listNode) {
            return;
        }

        let currentCourseId = Number(config.initialcourseid || 0);
        let timer = null;

        const escapeHtml = (value) => String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');

        const renderState = (type, message) => {
            statusNode.innerHTML = message ? `<div class="local-courseexams-${type}">${escapeHtml(message)}</div>` : '';
        };

        const renderSummary = (data) => {
            const stats = [
                [strings.courseid_short, data.course.id],
                [strings.exams, data.summary.totalexams],
                [strings.assignments, data.summary.assigncount],
                [strings.quizzes, data.summary.quizcount],
                [strings.visiblecount, data.summary.visiblecount],
                [strings.hiddencount, data.summary.hiddencount],
                [strings.overrides, data.summary.overridecount],
                [strings.questions, data.summary.quizquestioncount],
            ];

            summaryNode.innerHTML = stats.map(([label, value]) => `
                <article class="local-courseexams-stat">
                    <span class="local-courseexams-stat-label">${escapeHtml(label)}</span>
                    <span class="local-courseexams-stat-value">${escapeHtml(value)}</span>
                </article>
            `).join('');
        };

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

        const renderExamCard = (exam) => {
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
                <article class="local-courseexams-card">
                    <div class="local-courseexams-card-header">
                        <div class="local-courseexams-card-heading">
                            <h3 class="local-courseexams-card-title">${escapeHtml(exam.name)}</h3>
                            <div class="local-courseexams-chip-row">
                                <span class="local-courseexams-chip">${escapeHtml(exam.type_label)}</span>
                                <span class="local-courseexams-chip">${escapeHtml(exam.section.label)}</span>
                                <span class="local-courseexams-chip ${exam.visible ? '' : 'hidden'}">${escapeHtml(exam.visible_label)}</span>
                                <span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(strings.cmid)} ${escapeHtml(exam.cmid)}</span>
                                <span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(strings.instanceid)} ${escapeHtml(exam.instanceid)}</span>
                            </div>
                        </div>
                    </div>
                    <div class="local-courseexams-card-body">
                        <div class="local-courseexams-meta">
                            ${exam.meta.map((item) => `
                                <div class="local-courseexams-meta-item">
                                    <span class="local-courseexams-meta-label">${escapeHtml(item.label)}</span>
                                    <span class="local-courseexams-meta-value">${escapeHtml(item.value)}</span>
                                </div>
                            `).join('')}
                        </div>
                        <div class="local-courseexams-chip-row local-courseexams-chip-row-tight">
                            <span class="local-courseexams-chip">${escapeHtml(strings.overrides)}: ${escapeHtml(exam.overrides.total)}</span>
                            ${exam.overrides.summary.map((item) => `<span class="local-courseexams-chip local-courseexams-chip-muted">${escapeHtml(item)}</span>`).join('')}
                        </div>
                        ${overrideBlocks}
                        ${questionDetails}
                    </div>
                </article>
            `;
        };

        const renderData = (data) => {
            renderState('note', `${strings.refreshedat} ${data.generated.label}`);
            renderSummary(data);
            listNode.innerHTML = data.exams.length
                ? data.exams.map(renderExamCard).join('')
                : `<div class="local-courseexams-note">${escapeHtml(strings.empty || '')}</div>`;
        };

        const loadCourse = async (courseId) => {
            if (!courseId || Number.isNaN(courseId) || courseId < 1) {
                renderState('error', strings.invalidcourseid || '');
                summaryNode.innerHTML = '';
                listNode.innerHTML = '';
                return;
            }

            renderState('note', strings.loading || '');

            const body = new URLSearchParams({
                sesskey: config.sesskey,
                courseid: String(courseId),
            });

            try {
                const response = await fetch(config.ajaxurl, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    },
                    body,
                });
                const payload = await response.json();

                if (!response.ok || payload.status !== 'ok') {
                    throw new Error(payload.message || 'Erreur');
                }

                renderData(payload.data);
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

        form.addEventListener('submit', (event) => {
            event.preventDefault();
            currentCourseId = Number(input.value);
            const url = new URL(window.location.href);
            url.searchParams.set('courseid', String(currentCourseId));
            window.history.replaceState({}, '', url);
            loadCourse(currentCourseId);
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
