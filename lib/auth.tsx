"use client";

import {
  createContext,
  useContext,
  useEffect,
  useState,
  ReactNode,
} from "react";
import { useRouter } from "next/navigation";
import { api, User, ApiResponse } from "./api";

interface AuthState {
  user: User | null;
  token: string | null;
  loading: boolean;
  login: (email: string, password: string) => Promise<void>;
  logout: () => Promise<void>;
  isAdmin: boolean;
}

const AuthContext = createContext<AuthState | null>(null);

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<User | null>(null);
  const [token, setToken] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);
  const router = useRouter();

  useEffect(() => {
    const saved = localStorage.getItem("crm_token");
    if (saved) {
      setToken(saved);
      api
        .get<ApiResponse<User>>("/auth/me")
        .then((r) => setUser(r.data))
        .catch(() => {
          localStorage.removeItem("crm_token");
          setToken(null);
        })
        .finally(() => setLoading(false));
    } else {
      setLoading(false);
    }
  }, []);

  const login = async (email: string, password: string) => {
    const res = await api.post<ApiResponse<{ user: User; token: string; role: string }>>(
      "/auth/login",
      { email, password }
    );
    const { user: u, token: t } = res.data;
    localStorage.setItem("crm_token", t);
    setToken(t);
    setUser(u);
    router.push("/dashboard");
  };

  const logout = async () => {
    try {
      await api.post("/auth/logout", {});
    } finally {
      localStorage.removeItem("crm_token");
      setToken(null);
      setUser(null);
      router.push("/login");
    }
  };

  return (
    <AuthContext.Provider
      value={{ user, token, loading, login, logout, isAdmin: user?.role === "admin" }}
    >
      {children}
    </AuthContext.Provider>
  );
}

export function useAuth(): AuthState {
  const ctx = useContext(AuthContext);
  if (!ctx) throw new Error("useAuth must be used inside AuthProvider");
  return ctx;
}
