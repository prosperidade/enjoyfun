const ACCESS_TOKEN_KEY = "access_token";
const REFRESH_TOKEN_KEY = "refresh_token";
const USER_KEY = "enjoyfun_user";

const sessionState = {
  hydrated: false,
  accessToken: "",
  refreshToken: "",
  user: null,
};

function getStorage(name) {
  if (typeof window === "undefined") {
    return null;
  }

  try {
    return window[name];
  } catch {
    return null;
  }
}

function readJson(storage, key) {
  if (!storage) return null;

  try {
    const raw = storage.getItem(key);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
}

function writeJson(storage, key, value) {
  if (!storage) return;

  try {
    if (value === null || value === undefined) {
      storage.removeItem(key);
      return;
    }

    storage.setItem(key, JSON.stringify(value));
  } catch {
    // Ignore storage failures to preserve runtime flow.
  }
}

function writeString(storage, key, value) {
  if (!storage) return;

  try {
    if (!value) {
      storage.removeItem(key);
      return;
    }

    storage.setItem(key, value);
  } catch {
    // Ignore storage failures to preserve runtime flow.
  }
}

function removeLegacySession() {
  const legacyStorage = getStorage("localStorage");
  if (!legacyStorage) return;

  try {
    legacyStorage.removeItem(ACCESS_TOKEN_KEY);
    legacyStorage.removeItem(REFRESH_TOKEN_KEY);
    legacyStorage.removeItem(USER_KEY);
  } catch {
    // Ignore storage failures to preserve runtime flow.
  }
}

function hydrateSessionState() {
  if (sessionState.hydrated) {
    return;
  }

  const sessionStorageRef = getStorage("sessionStorage");
  const legacyStorage = getStorage("localStorage");

  const sessionAccessToken = sessionStorageRef?.getItem(ACCESS_TOKEN_KEY) || "";
  const sessionRefreshToken = sessionStorageRef?.getItem(REFRESH_TOKEN_KEY) || "";
  const sessionUser = readJson(sessionStorageRef, USER_KEY);

  if (sessionAccessToken || sessionRefreshToken || sessionUser) {
    sessionState.hydrated = true;
    sessionState.accessToken = sessionAccessToken;
    sessionState.refreshToken = sessionRefreshToken;
    sessionState.user = sessionUser;
    removeLegacySession();
    return;
  }

  const legacyAccessToken = legacyStorage?.getItem(ACCESS_TOKEN_KEY) || "";
  const legacyRefreshToken = legacyStorage?.getItem(REFRESH_TOKEN_KEY) || "";
  const legacyUser = readJson(legacyStorage, USER_KEY);

  sessionState.hydrated = true;
  sessionState.accessToken = legacyAccessToken;
  sessionState.refreshToken = legacyRefreshToken;
  sessionState.user = legacyUser;

  if (legacyAccessToken || legacyRefreshToken || legacyUser) {
    writeString(sessionStorageRef, ACCESS_TOKEN_KEY, legacyAccessToken);
    writeString(sessionStorageRef, REFRESH_TOKEN_KEY, legacyRefreshToken);
    writeJson(sessionStorageRef, USER_KEY, legacyUser);
    removeLegacySession();
  }
}

export function getAccessToken() {
  hydrateSessionState();
  return sessionState.accessToken;
}

export function getRefreshToken() {
  hydrateSessionState();
  return sessionState.refreshToken;
}

export function getStoredUser() {
  hydrateSessionState();
  return sessionState.user;
}

export function persistSession(result) {
  hydrateSessionState();

  if (!result?.access_token) {
    return;
  }

  const sessionStorageRef = getStorage("sessionStorage");
  sessionState.accessToken = result.access_token;
  sessionState.refreshToken = result.refresh_token || "";
  sessionState.user = result.user ?? null;

  writeString(sessionStorageRef, ACCESS_TOKEN_KEY, sessionState.accessToken);
  writeString(sessionStorageRef, REFRESH_TOKEN_KEY, sessionState.refreshToken);
  writeJson(sessionStorageRef, USER_KEY, sessionState.user);
  removeLegacySession();
}

export function persistUser(user) {
  hydrateSessionState();

  const sessionStorageRef = getStorage("sessionStorage");
  sessionState.user = user ?? null;
  writeJson(sessionStorageRef, USER_KEY, sessionState.user);
  removeLegacySession();
}

export function clearSession() {
  hydrateSessionState();

  const sessionStorageRef = getStorage("sessionStorage");
  sessionState.accessToken = "";
  sessionState.refreshToken = "";
  sessionState.user = null;

  writeString(sessionStorageRef, ACCESS_TOKEN_KEY, "");
  writeString(sessionStorageRef, REFRESH_TOKEN_KEY, "");
  writeJson(sessionStorageRef, USER_KEY, null);
  removeLegacySession();
}

export function getSessionSnapshot() {
  hydrateSessionState();

  return {
    accessToken: sessionState.accessToken,
    refreshToken: sessionState.refreshToken,
    user: sessionState.user,
  };
}
