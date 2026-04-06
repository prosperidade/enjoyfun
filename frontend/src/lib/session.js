const ACCESS_TOKEN_KEY = "access_token";
const REFRESH_TOKEN_KEY = "refresh_token";
const USER_KEY = "enjoyfun_user";
const ACCESS_TRANSPORT_KEY = "enjoyfun_access_transport";
const REFRESH_TRANSPORT_KEY = "enjoyfun_refresh_transport";
const HMAC_KEY = "enjoyfun_hmac_key";

const sessionState = {
  hydrated: false,
  accessToken: "",
  accessTransport: "cookie",   // Default: HttpOnly cookie (invisible to JS)
  refreshToken: "",
  refreshTransport: "cookie",  // Default: HttpOnly cookie
  user: null,
  hmacKey: "",
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

  const sessionAccessTransport = sessionStorageRef?.getItem(ACCESS_TRANSPORT_KEY) || "";
  const sessionRefreshTransport = sessionStorageRef?.getItem(REFRESH_TRANSPORT_KEY) || "";
  const sessionUser = readJson(sessionStorageRef, USER_KEY);
  const sessionHmacKey = sessionStorageRef?.getItem(HMAC_KEY) || "";

  // Cookie transport: tokens live in HttpOnly cookies — JS never sees them.
  // We only read token strings from sessionStorage when transport is "body".
  const sessionAccessToken = sessionAccessTransport === "body"
    ? (sessionStorageRef?.getItem(ACCESS_TOKEN_KEY) || "")
    : "";
  const sessionRefreshToken = sessionRefreshTransport === "body"
    ? (sessionStorageRef?.getItem(REFRESH_TOKEN_KEY) || "")
    : "";

  if (sessionUser || sessionAccessTransport || sessionRefreshTransport) {
    sessionState.hydrated = true;
    sessionState.accessToken = sessionAccessToken;
    sessionState.accessTransport = sessionAccessTransport || "cookie";
    sessionState.refreshToken = sessionRefreshToken;
    sessionState.refreshTransport = sessionRefreshTransport || "cookie";
    sessionState.user = sessionUser;
    sessionState.hmacKey = sessionHmacKey;

    // Clean up any stale token values left from the body-transport era
    if (sessionState.accessTransport === "cookie") {
      sessionStorageRef?.removeItem(ACCESS_TOKEN_KEY);
    }
    if (sessionState.refreshTransport === "cookie") {
      sessionStorageRef?.removeItem(REFRESH_TOKEN_KEY);
    }

    removeLegacySession();
    return;
  }

  // Legacy migration: move from localStorage to sessionStorage
  const legacyAccessToken = legacyStorage?.getItem(ACCESS_TOKEN_KEY) || "";
  const legacyRefreshToken = legacyStorage?.getItem(REFRESH_TOKEN_KEY) || "";
  const legacyUser = readJson(legacyStorage, USER_KEY);

  sessionState.hydrated = true;
  sessionState.accessToken = legacyAccessToken;
  sessionState.accessTransport = legacyAccessToken ? "body" : "cookie";
  sessionState.refreshToken = legacyRefreshToken;
  sessionState.refreshTransport = legacyRefreshToken ? "body" : "cookie";
  sessionState.user = legacyUser;

  if (legacyAccessToken || legacyRefreshToken || legacyUser) {
    // Only persist token strings when transport is body
    if (legacyAccessToken) {
      writeString(sessionStorageRef, ACCESS_TOKEN_KEY, legacyAccessToken);
      writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, "body");
    }
    if (legacyRefreshToken) {
      writeString(sessionStorageRef, REFRESH_TOKEN_KEY, legacyRefreshToken);
      writeString(sessionStorageRef, REFRESH_TRANSPORT_KEY, "body");
    }
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

  const accessTransport = result?.access_transport === "cookie" ? "cookie" : "body";
  const refreshTransport = result?.refresh_transport === "cookie" ? "cookie" : "body";

  // When transport is cookie, tokens live in HttpOnly cookies — never store in JS.
  const nextAccessToken = accessTransport === "cookie" ? "" : (result?.access_token || "");
  const nextRefreshToken = refreshTransport === "cookie" ? "" : (result?.refresh_token || "");

  if (!result || (!nextAccessToken && accessTransport !== "cookie")) {
    return;
  }

  const sessionStorageRef = getStorage("sessionStorage");
  sessionState.accessToken = nextAccessToken;
  sessionState.accessTransport = accessTransport;
  sessionState.refreshTransport = refreshTransport;
  sessionState.refreshToken = nextRefreshToken;
  sessionState.user = result.user ?? null;
  sessionState.hmacKey = result.hmac_key || sessionState.hmacKey || "";

  // Only write token strings to sessionStorage when transport is body.
  // Cookie-transported tokens are invisible to JS — never touch sessionStorage.
  if (accessTransport === "body") {
    writeString(sessionStorageRef, ACCESS_TOKEN_KEY, sessionState.accessToken);
  } else {
    // Purge any leftover token from a previous body-transport session
    sessionStorageRef?.removeItem(ACCESS_TOKEN_KEY);
  }

  if (refreshTransport === "body") {
    writeString(sessionStorageRef, REFRESH_TOKEN_KEY, sessionState.refreshToken);
  } else {
    sessionStorageRef?.removeItem(REFRESH_TOKEN_KEY);
  }

  // Transport flags and non-sensitive metadata always persist for UI/bootstrap
  writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, sessionState.accessTransport);
  writeString(sessionStorageRef, REFRESH_TRANSPORT_KEY, sessionState.refreshTransport);
  writeJson(sessionStorageRef, USER_KEY, sessionState.user);
  writeString(sessionStorageRef, HMAC_KEY, sessionState.hmacKey);
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
  sessionState.accessTransport = "cookie";
  sessionState.refreshToken = "";
  sessionState.refreshTransport = "cookie";
  sessionState.user = null;
  sessionState.hmacKey = "";

  // Remove all token-related keys from sessionStorage
  sessionStorageRef?.removeItem(ACCESS_TOKEN_KEY);
  sessionStorageRef?.removeItem(REFRESH_TOKEN_KEY);
  writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, "");
  writeString(sessionStorageRef, REFRESH_TRANSPORT_KEY, "");
  writeJson(sessionStorageRef, USER_KEY, null);
  writeString(sessionStorageRef, HMAC_KEY, "");
  removeLegacySession();
}

export function getHmacKey() {
  hydrateSessionState();
  return sessionState.hmacKey;
}

export function getSessionSnapshot() {
  hydrateSessionState();

  return {
    accessToken: sessionState.accessToken,
    accessTransport: sessionState.accessTransport,
    refreshToken: sessionState.refreshToken,
    refreshTransport: sessionState.refreshTransport,
    user: sessionState.user,
    hmacKey: sessionState.hmacKey,
  };
}
