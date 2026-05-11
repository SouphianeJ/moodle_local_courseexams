import { chromium } from 'playwright';
import os from 'node:os';
import path from 'node:path';

const baseUrl = process.env.MOODLE_BASE_URL || 'https://141.94.95.125:8443';
const courseId = 15;
const artifactsDir = process.env.PW_ARTIFACTS_DIR || os.tmpdir();

async function login(page, username, password) {
  await page.goto(`${baseUrl}/login/index.php`, { waitUntil: 'networkidle' });
  await page.fill('input[name="username"]', username);
  await page.fill('input[name="password"]', password);
  await Promise.all([
    page.waitForLoadState('networkidle'),
    page.click('button[type="submit"], input[type="submit"]'),
  ]);
}

async function verifyTeacher() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ ignoreHTTPSErrors: true });
  await login(page, 'e2eteacher', 'TeacherPass!2026');
  await page.goto(`${baseUrl}/local/courseexams/index.php`, { waitUntil: 'networkidle' });
  await page.fill('#local-courseexams-courseid', 'E2E');
  await page.waitForSelector('.local-courseexams-search-option', { timeout: 20000 });
  await page.locator('.local-courseexams-search-option').first().click();
  await Promise.all([
    page.waitForSelector('.local-courseexams-exam-row', { timeout: 20000 }),
    page.click('button[type="submit"]'),
  ]);
  await page.locator('#local-courseexams-archived-toggle').click();
  await page.waitForTimeout(1000);

  const rowToggles = page.locator('.local-courseexams-row-toggle');
  for (let i = 0; i < await rowToggles.count(); i++) {
    await rowToggles.nth(i).click();
    await page.waitForTimeout(250);
  }

  const questionDetails = page.locator('.local-courseexams-details summary');
  for (let i = 0; i < await questionDetails.count(); i++) {
    const text = await questionDetails.nth(i).innerText();
    if (text.includes('Questions')) {
      await questionDetails.nth(i).click();
      await page.waitForTimeout(250);
    }
  }

  const rows = await page.locator('.local-courseexams-exam-row').count();
  const summary = await page.locator('.local-courseexams-summary').innerText();
  const pageText = await page.locator('body').innerText();

  await page.screenshot({ path: path.join(artifactsDir, 'teacher-dashboard.png'), fullPage: true });
  await browser.close();

  return {
    rows,
    summary,
    hasAssignmentAlpha: pageText.includes('Assignment Alpha'),
    hasAssignmentBeta: pageText.includes('Assignment Beta Hidden'),
    hasQuizGamma: pageText.includes('Quiz Gamma'),
    hasQuizDelta: pageText.includes('Quiz Delta'),
    hasQuestion: pageText.includes('Quiz Gamma MCQ 1') || pageText.includes('Questions (4)'),
  };
}

async function verifyStudentDenied() {
  const browser = await chromium.launch({ headless: true });
  const page = await browser.newPage({ ignoreHTTPSErrors: true });
  await login(page, 'e2estudent', 'StudentPass!2026');
  await page.goto(`${baseUrl}/local/courseexams/index.php?courseid=${courseId}`, { waitUntil: 'networkidle' });
  await page.waitForTimeout(3000);
  const pageText = await page.locator('body').innerText();
  await page.screenshot({ path: path.join(artifactsDir, 'student-denied.png'), fullPage: true });
  await browser.close();

  return {
    denied: pageText.includes('Access denied') || pageText.includes('Acces refuse'),
    pageText,
  };
}

const teacher = await verifyTeacher();
const student = await verifyStudentDenied();

console.log(JSON.stringify({ teacher, student }, null, 2));
