/* ============================================================
   Quiznosis — API client
   Wraps every backend endpoint as a Promise-returning function.
   Auto-detects API base, sends cookies, normalizes errors.
   ============================================================ */

const API_BASE = (() => {
  // The frontend lives in a sibling folder of /api/ — e.g. /quiznosis/frontend/.
  // Strip the last path segment (the folder holding the HTML files) and the file,
  // then point at ../api. Works no matter what the frontend folder is named.
  const path = window.location.pathname;            // e.g. /quiznosis/frontend/index.html
  const dir = path.replace(/\/[^/]*$/, '');         // -> /quiznosis/frontend
  const parent = dir.replace(/\/[^/]*$/, '');       // -> /quiznosis
  return (parent || '') + '/api';
})();

// mod_rewrite isn't guaranteed, so we append .php to endpoint paths.
const PHP = '.php';

class ApiError extends Error {
  constructor(message, status, data) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.data = data || {};
  }
}

function buildUrl(path) {
  // Insert .php before any query string.
  if (path.includes('.php')) return API_BASE + path;
  const qi = path.indexOf('?');
  if (qi >= 0) return API_BASE + path.slice(0, qi) + PHP + path.slice(qi);
  return API_BASE + path + PHP;
}

async function request(method, path, body = null, opts = {}) {
  const init = {
    method,
    credentials: 'include',
    headers: { 'Accept': 'application/json' },
    ...opts,
  };
  if (body !== null && body !== undefined) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(body);
  }
  let res;
  try {
    res = await fetch(buildUrl(path), init);
  } catch (e) {
    throw new ApiError('Network error — is the server running? ' + e.message, 0, null);
  }
  let data;
  const text = await res.text();
  try { data = text ? JSON.parse(text) : {}; }
  catch { data = { error: true, message: text || res.statusText }; }

  if (!res.ok) {
    throw new ApiError(data.message || data.error || ('Error ' + res.status), res.status, data);
  }
  return data;
}

/**
 * Multipart upload helper. Pass a FormData (or a plain object that gets
 * coerced to FormData). Use methodOverride for PUT/PATCH/DELETE — the
 * backend honors POST + _method via Request::method().
 */
async function uploadRequest(method, path, fields, { methodOverride } = {}) {
  const fd = fields instanceof FormData ? fields : new FormData();
  if (!(fields instanceof FormData) && fields) {
    for (const [k, v] of Object.entries(fields)) {
      if (v !== null && v !== undefined) fd.append(k, v);
    }
  }
  if (methodOverride) fd.append('_method', methodOverride);
  let res;
  try {
    res = await fetch(buildUrl(path), {
      method: methodOverride ? 'POST' : method,
      credentials: 'include',
      headers: { 'Accept': 'application/json' },
      body: fd,
    });
  } catch (e) {
    throw new ApiError('Network error — ' + e.message, 0, null);
  }
  const text = await res.text();
  let data;
  try { data = text ? JSON.parse(text) : {}; }
  catch { data = { error: true, message: text || res.statusText }; }
  if (!res.ok) {
    throw new ApiError(data.message || ('Error ' + res.status), res.status, data);
  }
  return data;
}

function qs(params) {
  const e = Object.entries(params || {}).filter(([, v]) => v !== undefined && v !== null && v !== '');
  if (!e.length) return '';
  return '?' + e.map(([k, v]) => encodeURIComponent(k) + '=' + encodeURIComponent(v)).join('&');
}

/* --- Auth ---------------------------------------------------------- */
export const auth = {
  register:           (body)  => request('POST', '/auth/register', body),
  login:              (body)  => request('POST', '/auth/login', body),
  logout:             ()      => request('POST', '/auth/logout'),
  me:                 ()      => request('GET',  '/auth/me'),
  verify:             (body)  => request('POST', '/auth/verify', body),
  resendVerification: (email) => request('POST', '/auth/resend-verification', { email }),
  forgot:             (email) => request('POST', '/auth/forgot', { email }),
  reset:              (body)  => request('POST', '/auth/reset', body),
  changePassword:     (body)  => request('POST', '/auth/change-password', body),
  googleOAuth:        (idToken) => request('POST', '/auth/google', { id_token: idToken }),
  telegramLogin:      (data)    => request('POST', '/auth/telegram', data),
};

/* --- Quiz flow ----------------------------------------------------- */
export const quiz = {
  listSets:    (params = {}) => request('GET',  '/quiz/sets' + qs(params)),
  getSet:      (params = {}) => request('GET',  '/quiz/set' + qs(params)),
  set:         (params = {}) => request('GET',  '/quiz/set' + qs(params)),
  checkAccess: (quizSetId)              => request('GET',  '/quiz/access' + qs({ quizSetId })),
  start:       (quizSetId, mode = null) => request('POST', '/quiz/start', mode ? { quizSetId, mode } : { quizSetId }),
  answer:      (body)        => request('POST', '/quiz/answer', body),
  submit:      (attemptId, elapsedSec = null) => request('POST', '/quiz/submit', elapsedSec != null ? { attemptId, elapsedSec } : { attemptId }),
  pause:       (attemptId, elapsedSeconds) => request('POST', '/quiz/pause', { attemptId, elapsedSeconds }),
  resume:      (attemptId)   => request('GET',  '/quiz/resume' + qs({ attemptId })),
  results:     (attemptId)   => request('GET',  '/quiz/results' + qs({ attemptId })),
  leaderboard: (quizSetId, limit = 20) =>
                                request('GET',  '/quiz/leaderboard' + qs({ quizSetId, limit })),
};

/* --- Taxonomy ------------------------------------------------------ */
export const taxonomy = {
  professions: ()           => request('GET', '/professions'),
  subjects:    (params = {})=> request('GET', '/subjects' + qs(params)),
  topics:      (params = {})=> request('GET', '/topics' + qs(params)),
  examTypes:   (params = {})=> request('GET', '/exam-types' + qs(params)),
};

/* --- User-facing --------------------------------------------------- */
export const me = {
  profile:            ()      => request('GET',   '/profile'),
  updateProfile:      (patch) => request('PATCH', '/profile', patch),
  dashboard:          ()      => request('GET',   '/dashboard'),
  attempts:           (params = {}) => request('GET',   '/me/attempts' + qs(params)),
  stats:              ()      => request('GET',   '/me/stats'),
  notifications:      (params = {}) => request('GET', '/notifications' + qs(params)),
  notificationAction: (id, action)  => request('POST', '/notifications', { id, action }),
};

/* --- Announcements -------------------------------------------------- */
export const announcements = {
  list: () => request('GET', '/announcements'),
};

/* --- Reports ------------------------------------------------------- */
export const reports = {
  submit: (body) => request('POST', '/reports/submit', body),
};

/* --- Subscription -------------------------------------------------- */
export const subscription = {
  plans: () => request('GET', '/subscription/plans'),
  mine:  () => request('GET', '/subscription/mine'),
};

/* --- Purchases (student) ------------------------------------------ */
export const purchases = {
  mine:    (status)   => request('GET', '/purchases/mine' + qs(status ? { status } : {})),
  request: (formData) => uploadRequest('POST', '/purchases/request', formData),
};

/* --- Payment settings (public) ------------------------------------ */
export const paymentSettings = {
  get: () => request('GET', '/payment-settings'),
};

/* --- Courses (student-facing) -------------------------------------- */
export const courses = {
  list:   ()   => request('GET', '/courses'),
  get:    (id) => request('GET', '/courses' + qs({ id })),
  note:   (id) => request('GET', '/notes' + qs({ id })),
  searchNotes: (params) => request('GET', '/notes' + qs(params)),
  lesson: (id) => request('GET', '/lessons' + qs({ id })),
};

export const enrollments = {
  mine:    (status) => request('GET',  '/enrollments/mine' + qs(status ? { status } : {})),
  request: (body)   => request('POST', '/enrollments/request', body),
};

/* --- Course plans -------------------------------------------------- */
export const coursePlans = {
  list: (courseId) => request('GET', '/course-plans' + qs({ course_id: courseId })),
};

/* --- Admin --------------------------------------------------------- */
export const admin = {
  metrics:    ()           => request('GET',   '/admin/metrics'),

  listUsers:  (params = {})=> request('GET',   '/admin/users' + qs(params)),
  updateUser: (body)       => request('PATCH', '/admin/users', body),

  listQuizSets:  ()        => request('GET',    '/admin/quiz-sets'),
  createQuizSet: (body)    => request('POST',   '/admin/quiz-sets', body),
  updateQuizSet: (body)    => request('PATCH',  '/admin/quiz-sets', body),
  deleteQuizSet: (id)      => request('DELETE', '/admin/quiz-sets' + qs({ id })),

  listQuizzes:  (params={})=> request('GET',    '/admin/quizzes' + qs(params)),
  getQuiz:      (id)        => request('GET',    '/admin/quizzes' + qs({ id })),
  createQuiz:   (body)     => request('POST',   '/admin/quizzes', body),
  updateQuiz:   (body)     => request('PATCH',  '/admin/quizzes', body),
  deleteQuiz:   (id)       => request('DELETE', '/admin/quizzes' + qs({ id })),

  listSetItems: (setId)    => request('GET',    '/admin/set-items' + qs({ quiz_set_id: setId })),
  addSetItem:   (body)     => request('POST',   '/admin/set-items', body),
  reorderItems: (body)     => request('PUT',    '/admin/set-items', body),
  removeSetItem:(id)       => request('DELETE', '/admin/set-items' + qs({ id })),

  listReports:  (params={})=> request('GET',  '/admin/reports' + qs(params)),
  reviewReport: (body)     => request('POST', '/admin/reports', body),

  bulkImport:   (body)     => request('POST', '/admin/bulk-import', body),

  // Taxonomy CRUD
  listProfessions:  ()             => request('GET',    '/admin/professions'),
  createProfession: (body)         => request('POST',   '/admin/professions', body),
  updateProfession: (body)         => request('PATCH',  '/admin/professions', body),
  deleteProfession: (id)           => request('DELETE', '/admin/professions' + qs({ id })),

  listSubjects:     (params = {})  => request('GET',    '/admin/subjects' + qs(params)),
  createSubject:    (body)         => request('POST',   '/admin/subjects', body),
  updateSubject:    (body)         => request('PATCH',  '/admin/subjects', body),
  deleteSubject:    (id)           => request('DELETE', '/admin/subjects' + qs({ id })),

  listTopics:       (params = {})  => request('GET',    '/admin/topics' + qs(params)),
  createTopic:      (body)         => request('POST',   '/admin/topics', body),
  updateTopic:      (body)         => request('PATCH',  '/admin/topics', body),
  deleteTopic:      (id)           => request('DELETE', '/admin/topics' + qs({ id })),

  listExamTypes:    (params = {})  => request('GET',    '/admin/exam-types' + qs(params)),
  createExamType:   (body)         => request('POST',   '/admin/exam-types', body),
  updateExamType:   (body)         => request('PATCH',  '/admin/exam-types', body),
  deleteExamType:   (id)           => request('DELETE', '/admin/exam-types' + qs({ id })),

  // Announcements
  listAnnouncements:   ()      => request('GET',    '/admin/announcements'),
  createAnnouncement:  (body)  => request('POST',   '/admin/announcements', body),
  updateAnnouncement:  (body)  => request('PATCH',  '/admin/announcements', body),
  publishAnnouncement: (id)    => request('POST',   '/admin/announcements', { id, action: 'publish' }),
  deleteAnnouncement:  (id)    => request('DELETE', '/admin/announcements' + qs({ id })),

  // AI explanation assistant (DeepSeek or any OpenAI-compatible API — see Admin → AI Settings)
  getAiSettings:      ()      => request('GET',  '/admin/ai-settings'),
  saveAiSettings:     (body)  => request('POST', '/admin/ai-settings', body),
  improveExplanation: (body)  => request('POST', '/admin/ai-improve', body),

  // Subscription plans
  listSubscriptionPlans:  ()       => request('GET',    '/admin/subscription-plans'),
  createSubscriptionPlan: (body)   => request('POST',   '/admin/subscription-plans', body),
  updateSubscriptionPlan: (body)   => request('PATCH',  '/admin/subscription-plans', body),
  deleteSubscriptionPlan: (id)     => request('DELETE', '/admin/subscription-plans' + qs({ id })),

  // Purchases / Quiz Sets Requests
  listPurchases:    (params = {})  => request('GET',    '/admin/purchases' + qs(params)),
  reviewPurchase:   (body)         => request('POST',   '/admin/purchases', body),

  // Payment settings
  getPaymentSettings:    ()        => request('GET',  '/admin/payment-settings'),
  updatePaymentSettings: (body)    => request('POST', '/admin/payment-settings', body),
  uploadPaymentQr:       (formData)=> uploadRequest('POST', '/admin/payment-settings', formData, { methodOverride: 'PUT' }),

  // Courses
  listCourses:       ()            => request('GET',    '/admin/courses'),
  getCourse:         (id)          => request('GET',    '/admin/courses' + qs({ id })),
  createCourse:      (body)        => request('POST',   '/admin/courses', body),
  updateCourse:      (body)        => request('PATCH',  '/admin/courses', body),
  uploadCourseCover: (formData)    => uploadRequest('POST', '/admin/courses' + qs({ action: 'upload-cover' }), formData),
  removeCourseCover: (courseId)    => request('POST', '/admin/courses' + qs({ action: 'remove-cover' }), { courseId }),
  deleteCourse:      (id, force)   => request('DELETE', '/admin/courses' + qs(force ? { id, force: 1 } : { id })),
  addCourseMaterial: (body)        => request('POST',   '/admin/courses' + qs({ action: 'add-material' }), body),
  removeCourseMaterial: (materialId) => request('DELETE', '/admin/courses' + qs({ action: 'remove-material', materialId })),
  setMaterialFree: (materialId, isFreeDemo) => request('POST', '/admin/courses' + qs({ action: 'set-material-free' }), { materialId, isFreeDemo: !!isFreeDemo }),
  reorderCourseMaterials: (body)   => request('POST',   '/admin/courses' + qs({ action: 'reorder-materials' }), body),

  // Notes
  listNotes:   (params = {})       => request('GET',    '/admin/notes' + qs(params)),
  getNote:     (id)                => request('GET',    '/admin/notes' + qs({ id })),
  createNote:  (body)              => request('POST',   '/admin/notes', body),
  updateNote:  (body)              => request('PATCH',  '/admin/notes', body),
  deleteNote:  (id)                => request('DELETE', '/admin/notes' + qs({ id })),

  // Lessons
  listLessons:  (params = {})      => request('GET',    '/admin/lessons' + qs(params)),
  getLesson:    (id)               => request('GET',    '/admin/lessons' + qs({ id })),
  createLesson: (body)             => request('POST',   '/admin/lessons', body),
  updateLesson: (body)             => request('PATCH',  '/admin/lessons', body),
  deleteLesson: (id)               => request('DELETE', '/admin/lessons' + qs({ id })),
  attachLessonToNote:   (body)     => request('POST',   '/admin/lessons' + qs({ action: 'attach-to-note' }), body),
  detachLessonFromNote: (linkId)   => request('DELETE', '/admin/lessons' + qs({ action: 'detach-from-note', linkId })),
  reorderNoteLessons:   (body)     => request('POST',   '/admin/lessons' + qs({ action: 'reorder-note-lessons' }), body),

  // Contact messages
  listContactMessages: (params = {}) => request('GET',    '/contact' + qs(params)),
  contactAction:       (body)        => request('POST',   '/contact', body),
  deleteContactMessage:(id)          => request('DELETE', '/contact' + qs({ id })),

  // Enrollments
  listEnrollments:  (params = {})  => request('GET',  '/admin/enrollments' + qs(params)),
  reviewEnrollment: (body)         => request('POST', '/admin/enrollments', body),

  // Course plans (per-course subscription plans)
  listCoursePlans:  (courseId)     => request('GET',    '/admin/course-plans' + qs({ course_id: courseId })),
  createCoursePlan: (body)         => request('POST',   '/admin/course-plans', body),
  updateCoursePlan: (body)         => request('PATCH',  '/admin/course-plans', body),
  deleteCoursePlan: (id)           => request('DELETE', '/admin/course-plans' + qs({ id })),
};

export const bookmarks = {
  toggle:  (quizId)            => request('POST', '/bookmarks', { quizId }),
  list:    (page = 1, ps = 20) => request('GET',  '/bookmarks' + qs({ page, pageSize: ps })),
  count:   ()                  => request('GET',  '/bookmarks' + qs({ count: 1 })),
  status:  (ids)               => request('GET',  '/bookmarks' + qs({ status: 1, ids: ids.join(',') })),
  practice:()                  => request('GET',  '/bookmarks' + qs({ practice: 1 })),
};

export { ApiError };
