import { useState } from "react";
import LoginPage from "./pages/LoginPage";
import RegisterPage from "./pages/RegisterPage";

const USER_STORAGE_KEY = "ai-landing-auth-user";

const getStoredUser = () => {
  try {
    const raw = localStorage.getItem(USER_STORAGE_KEY);
    return raw ? JSON.parse(raw) : null;
  } catch {
    return null;
  }
};

const readErrorMessage = (data) => {
  if (!data) return "Terjadi kesalahan.";
  if (typeof data.message === "string" && data.message.trim() !== "") return data.message;
  if (data.errors && typeof data.errors === "object") {
    const firstField = Object.values(data.errors)[0];
    if (Array.isArray(firstField) && firstField[0]) return firstField[0];
  }
  return "Terjadi kesalahan.";
};

export default function App() {
  const [view, setView] = useState(() => (getStoredUser() ? "generator" : "login"));
  const [user, setUser] = useState(getStoredUser);

  const [form, setForm] = useState({
    company_name: "",
    product: "",
    audience: "",
    tone: "profesional",
    cta: "Hubungi Kami",
    contact: "",
    brand_color: "",
  });

  const [loginForm, setLoginForm] = useState({
    email: "",
    password: "",
  });

  const [registerForm, setRegisterForm] = useState({
    name: "",
    email: "",
    password: "",
    password_confirmation: "",
  });

  const [loading, setLoading] = useState(false);
  const [html, setHtml] = useState("");
  const [err, setErr] = useState("");
  const [authLoading, setAuthLoading] = useState(false);
  const [authErr, setAuthErr] = useState("");
  const [authMsg, setAuthMsg] = useState("");

  const onChange = (e) => setForm({ ...form, [e.target.name]: e.target.value });
  const onLoginChange = (e) => setLoginForm({ ...loginForm, [e.target.name]: e.target.value });
  const onRegisterChange = (e) => setRegisterForm({ ...registerForm, [e.target.name]: e.target.value });

  const setLoggedInUser = (nextUser) => {
    setUser(nextUser);
    localStorage.setItem(USER_STORAGE_KEY, JSON.stringify(nextUser));
  };

  const logout = async () => {
    setAuthErr("");
    setAuthMsg("");

    try {
      await fetch("/api/logout", { method: "POST" });
    } finally {
      localStorage.removeItem(USER_STORAGE_KEY);
      setUser(null);
      setView("login");
      setAuthMsg("Kamu sudah logout.");
    }
  };

  const register = async (e) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthErr("");
    setAuthMsg("");

    try {
      const res = await fetch("/api/register", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(registerForm),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(readErrorMessage(data));
      }

      setAuthMsg("Registrasi berhasil. Silakan login.");
      setRegisterForm({
        name: "",
        email: "",
        password: "",
        password_confirmation: "",
      });
      setView("login");
    } catch (e2) {
      setAuthErr(String(e2.message || e2));
    } finally {
      setAuthLoading(false);
    }
  };

  const login = async (e) => {
    e.preventDefault();
    setAuthLoading(true);
    setAuthErr("");
    setAuthMsg("");

    try {
      const res = await fetch("/api/login", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(loginForm),
      });

      const data = await res.json();
      if (!res.ok) {
        throw new Error(readErrorMessage(data));
      }

      setLoggedInUser(data.user);
      setView("generator");
      setLoginForm({ email: "", password: "" });
      setAuthMsg("Login berhasil.");
    } catch (e2) {
      setAuthErr(String(e2.message || e2));
    } finally {
      setAuthLoading(false);
    }
  };

  const generate = async () => {
    setLoading(true);
    setErr("");
    setHtml("");

    try {
      const res = await fetch("/api/generate", {
        method: "POST",
        headers: {
          "Content-Type": "application/json",
          Accept: "application/json",
        },
        body: JSON.stringify(form),
      });

      const contentType = res.headers.get("content-type") || "";
      if (!contentType.includes("application/json")) {
        const text = await res.text();
        throw new Error(
          `Server mengembalikan non-JSON (${res.status}). ` +
            "Pastikan backend /api berjalan dan tidak mengirim HTML. " +
            `Cuplikan: ${text.slice(0, 120)}`
        );
      }

      const data = await res.json();
      if (!res.ok) {
        throw new Error(data?.message || data?.ai?.[0] || "Gagal");
      }

      setHtml(data.html);
    } catch (e) {
      setErr(String(e.message || e));
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="pageWrap">
      <header className="siteHeader">
        <div className="siteHeaderInner">
          <button
            className="navTitle border-0 bg-transparent p-0 text-left"
            type="button"
            onClick={() => setView(user ? "generator" : "login")}
          >
            AI to Landing Page Generator
          </button>
          <div className="navLinks">
            {user ? (
              <>
                <span>{user.name}</span>
                <span className="navDivider">|</span>
                <button className="navLink" type="button" onClick={logout}>
                  Logout
                </button>
              </>
            ) : (
              <>
                <button className="navLink" type="button" onClick={() => setView("login")}>
                  Login
                </button>
                <span className="navDivider">|</span>
                <button className="navLink" type="button" onClick={() => setView("register")}>
                  Register
                </button>
              </>
            )}
          </div>
        </div>
      </header>

      <main className="mainWrap">
        <div className="shell">
          <div className="navBar">
            <p className="tagline">Generate clean, ready to use landing pages in seconds</p>
          </div>

          {authMsg && <div className="mt-3 text-[12px] md:text-[13px] text-green-700">{authMsg}</div>}
          {authErr && <div className="mt-3 text-[12px] md:text-[13px] text-red-600">{authErr}</div>}

          {view === "login" ? (
            <LoginPage
              form={loginForm}
              onChange={onLoginChange}
              onSubmit={login}
              loading={authLoading}
            />
          ) : null}

          {view === "register" ? (
            <RegisterPage
              form={registerForm}
              onChange={onRegisterChange}
              onSubmit={register}
              loading={authLoading}
            />
          ) : null}

          {view === "generator" && user ? (
            <div className="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-12">
              <section className="lg:col-span-5">
                <div className="card cardSoft">
                  <div className="grid gap-3">
                    <Input
                      label="Company Name"
                      name="company_name"
                      value={form.company_name}
                      onChange={onChange}
                      placeholder="Company Name"
                    />
                    <Input
                      label="Product / Services"
                      name="product"
                      value={form.product}
                      onChange={onChange}
                      placeholder="Example: AI-powered landing page generator"
                    />
                    <Input
                      label="Audience Target"
                      name="audience"
                      value={form.audience}
                      onChange={onChange}
                      placeholder="Example: Parents, Adults"
                    />

                    <div>
                      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">
                        Tone
                      </label>
                      <select name="tone" value={form.tone} onChange={onChange} className="input">
                        <option value="profesional">Professional</option>
                        <option value="formal">Formal</option>
                        <option value="santai">Casual</option>
                      </select>
                    </div>

                    <Input
                      label="CTA Button"
                      name="cta"
                      value={form.cta}
                      onChange={onChange}
                      placeholder="Example: Contact Us, Buy Now"
                    />
                    <Input
                      label="Contact (optional)"
                      name="contact"
                      value={form.contact}
                      onChange={onChange}
                      placeholder=""
                    />
                    <Input
                      label="Color Brand (optional)"
                      name="brand_color"
                      value={form.brand_color}
                      onChange={onChange}
                      placeholder=""
                    />

                    <button onClick={generate} disabled={loading} className="btnPrimary mt-1">
                      {loading ? "Generating..." : "Generate Landing Page"}
                    </button>

                    {err && <div className="text-[12px] md:text-[13px] text-red-600">{err}</div>}
                  </div>
                </div>
              </section>

              <section className="lg:col-span-7 flex flex-col gap-6">
                <div className="card">
                  <div className="flex items-center justify-between">
                    <h2 className="text-[14px] md:text-[16px] font-semibold text-[#111111]">Output</h2>
                    <button
                      className="btnSmall"
                      disabled={!html}
                      onClick={() => navigator.clipboard.writeText(html)}
                    >
                      Copy HTML
                    </button>
                  </div>

                  <div className="mt-3 grid gap-3">
                    <textarea
                      className="codeBox"
                      value={html}
                      readOnly
                      placeholder="Your generated HTML code will appear here"
                    />
                  </div>
                </div>

                <div className="grid gap-2">
                  <div className="text-[13px] md:text-[14px] font-semibold text-[#111111]">Preview</div>
                  <div className="previewCard">
                    <iframe title="preview" className="h-full w-full" srcDoc={html} />
                  </div>
                </div>
              </section>
            </div>
          ) : null}
        </div>
      </main>
    </div>
  );
}

function Input({ label, ...props }) {
  return (
    <div>
      <label className="text-[12px] md:text-[13px] font-medium text-[#111111]">{label}</label>
      <input {...props} className="input" />
    </div>
  );
}
