const ACCESS_TOKEN_KEY = "access_token";
const REFRESH_TOKEN_KEY = "refresh_token";
const USER_KEY = "enjoyfun_user";
const ACCESS_TRANSPORT_KEY = "enjoyfun_access_transport";
const REFRESH_TRANSPORT_KEY = "enjoyfun_refresh_transport";
const HMAC_KEY = "enjoyfun_hmac_key";

const sessionState = {
  hydrated: false,
  accessToken: "",
  accessTransport: "body",
  refreshToken: "",
  refreshTransport: "body",
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

  const sessionAccessToken = sessionStorageRef?.getItem(ACCESS_TOKEN_KEY) || "";
  const sessionAccessTransport = sessionStorageRef?.getItem(ACCESS_TRANSPORT_KEY) || "";
  const sessionRefreshToken = sessionStorageRef?.getItem(REFRESH_TOKEN_KEY) || "";
  const sessionRefreshTransport = sessionStorageRef?.getItem(REFRESH_TRANSPORT_KEY) || "";
  const sessionUser = readJson(sessionStorageRef, USER_KEY);
  const sessionHmacKey = sessionStorageRef?.getItem(HMAC_KEY) || "";

  if (sessionAccessToken || sessionRefreshToken || sessionUser || sessionRefreshTransport || sessionAccessTransport) {
    sessionState.hydrated = true;
    sessionState.accessToken = sessionAccessToken;
    sessionState.accessTransport = sessionAccessTransport || "body";
    sessionState.refreshToken = sessionRefreshToken;
    sessionState.refreshTransport = sessionRefreshTransport || "body";
    sessionState.user = sessionUser;
    sessionState.hmacKey = sessionHmacKey;
    removeLegacySession();
    return;
  }

  const legacyAccessToken = legacyStorage?.getItem(ACCESS_TOKEN_KEY) || "";
  const legacyRefreshToken = legacyStorage?.getItem(REFRESH_TOKEN_KEY) || "";
  const legacyUser = readJson(legacyStorage, USER_KEY);

  sessionState.hydrated = true;
  sessionState.accessToken = legacyAccessToken;
  sessionState.accessTransport = legacyAccessToken ? "body" : "body";
  sessionState.refreshToken = legacyRefreshToken;
  sessionState.refreshTransport = legacyRefreshToken ? "body" : "body";
  sessionState.user = legacyUser;

  if (legacyAccessToken || legacyRefreshToken || legacyUser) {
    writeString(sessionStorageRef, ACCESS_TOKEN_KEY, legacyAccessToken);
    writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, legacyAccessToken ? "body" : "");
    writeString(sessionStorageRef, REFRESH_TOKEN_KEY, legacyRefreshToken);
    writeString(sessionStorageRef, REFRESH_TRANSPORT_KEY, legacyRefreshToken ? "body" : "");
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

  writeString(sessionStorageRef, ACCESS_TOKEN_KEY, sessionState.accessToken);
  writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, sessionState.accessTransport);
  writeString(sessionStorageRef, REFRESH_TOKEN_KEY, sessionState.refreshToken);
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
  sessionState.accessTransport = "body";
  sessionState.refreshToken = "";
  sessionState.refreshTransport = "body";
  sessionState.user = null;
  sessionState.hmacKey = "";

  writeString(sessionStorageRef, ACCESS_TOKEN_KEY, "");
  writeString(sessionStorageRef, ACCESS_TRANSPORT_KEY, "");
  writeString(sessionStorageRef, REFRESH_TOKEN_KEY, "");
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
