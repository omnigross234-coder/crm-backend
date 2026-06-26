"use client";
import { useEffect, useState, FormEvent } from "react";
import { useRouter } from "next/navigation";
import Layout from "@/components/Layout";
import { api, User, ApiResponse } from "@/lib/api";
import { useAuth } from "@/lib/auth";

export default function UsersPage() {
  const { user, isAdmin, loading: authLoading } = useAuth();
  const router = useRouter();
  const [users, setUsers] = useState<User[]>([]);
  const [loading, setLoading] = useState(true);

  // new user form
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [role, setRole] = useState<"sales" | "admin">("sales");
  const [adding, setAdding] = useState(false);
  const [error, setError] = useState("");

  // edit user form
  const [editingId, setEditingId] = useState<number | null>(null);
  const [editName, setEditName] = useState("");
  const [editEmail, setEditEmail] = useState("");
  const [editPassword, setEditPassword] = useState("");
  const [editRole, setEditRole] = useState<"sales" | "admin">("sales");
  const [editStatus, setEditStatus] = useState<"active" | "inactive">("active");
  const [savingId, setSavingId] = useState<number | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  // status toggle
  const [togglingId, setTogglingId] = useState<number | null>(null);

  useEffect(() => {
    if (!authLoading && !isAdmin) {
      router.replace("/dashboard");
      return;
    }
    if (!authLoading) {
      api
        .get<ApiResponse<User[]>>("/users")
        .then((r) => setUsers(r.data))
        .finally(() => setLoading(false));
    }
  }, [authLoading, isAdmin, router]);

  async function handleAddUser(e: FormEvent) {
    e.preventDefault();
    if (!name.trim() || !email.trim() || !password.trim()) return;
    if (users.some((u) => u.email.toLowerCase() === email.trim().toLowerCase())) {
      setError("A user with this email already exists.");
      return;
    }
    if (password.length < 8) {
      setError("Password must be at least 8 characters.");
      return;
    }
    setError("");
    setAdding(true);
    try {
      const res = await api.post<ApiResponse<User>>("/users", { name, email, password, role });
      setUsers((prev) => [...prev, res.data]);
      setName("");
      setEmail("");
      setPassword("");
      setRole("sales");
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to create user");
    } finally {
      setAdding(false);
    }
  }

  function startEdit(u: User) {
    setError("");
    setEditingId(u.id);
    setEditName(u.name);
    setEditEmail(u.email);
    setEditPassword("");
    setEditRole(u.role);
    setEditStatus((u.status as "active" | "inactive") ?? "active");
  }

  function cancelEdit() {
    setEditingId(null);
    setEditName("");
    setEditEmail("");
    setEditPassword("");
    setEditRole("sales");
    setEditStatus("active");
  }

  async function handleUpdateUser(e: FormEvent) {
    e.preventDefault();
    if (!editingId || !editName.trim() || !editEmail.trim()) return;
    setError("");
    setSavingId(editingId);
    try {
      const body: {
        name: string;
        email: string;
        role: "sales" | "admin";
        status: "active" | "inactive";
        password?: string;
      } = {
        name: editName,
        email: editEmail,
        role: editRole,
        status: editStatus,
      };

      if (editPassword.trim()) {
        body.password = editPassword;
      }

      const res = await api.put<ApiResponse<User>>(`/users/${editingId}`, body);
      setUsers((prev) => prev.map((u) => (u.id === editingId ? res.data : u)));
      cancelEdit();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update user");
    } finally {
      setSavingId(null);
    }
  }

  async function handleToggleStatus(u: User) {
    if (u.id === user?.id) {
      setError("You cannot change your own status.");
      return;
    }
    setTogglingId(u.id);
    setError("");
    try {
      const res = await api.patch<ApiResponse<{ id: number; status: string }>>(
  `/users/${u.id}/toggle-status`,
  {}  
);
      setUsers((prev) =>
  prev.map((item) =>
    item.id === u.id ? { ...item, status: res.data.status as "active" | "inactive" } : item
  )
);
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to update status");
    } finally {
      setTogglingId(null);
    }
  }

  async function handleDeleteUser(u: User) {
    if (u.id === user?.id) {
      setError("You cannot delete your own account while logged in.");
      return;
    }
    if (!confirm(`Delete ${u.name}? This cannot be undone.`)) return;
    setError("");
    setDeletingId(u.id);
    try {
      await api.delete(`/users/${u.id}`);
      setUsers((prev) => prev.filter((item) => item.id !== u.id));
      if (editingId === u.id) cancelEdit();
    } catch (err: unknown) {
      setError(err instanceof Error ? err.message : "Failed to delete user");
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <Layout>
      <div className="p-8">
        <h1 className="text-2xl font-bold text-gray-900 mb-6">Users</h1>

        {/* Add user form */}
        <div className="bg-white rounded-xl border border-gray-200 p-6 mb-6">
          <h2 className="font-semibold text-gray-800 mb-4">Add New User</h2>
          {error && (
            <div className="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3">
              {error}
            </div>
          )}
          <form onSubmit={handleAddUser} className="grid grid-cols-2 lg:grid-cols-5 gap-3">
            <input
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder="Full name"
              required
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <input
              type="email"
              value={email}
              onChange={(e) => setEmail(e.target.value)}
              placeholder="Email"
              required
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <input
              type="password"
              value={password}
              onChange={(e) => setPassword(e.target.value)}
              placeholder="Password"
              required
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            />
            <select
              value={role}
              onChange={(e) => setRole(e.target.value as "sales" | "admin")}
              className="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
            >
              <option value="sales">Sales</option>
              <option value="admin">Admin</option>
            </select>
            <button
              type="submit"
              disabled={adding}
              className="bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
            >
              {adding ? "Adding…" : "Add User"}
            </button>
          </form>
        </div>

        {/* Users table */}
        <div className="bg-white rounded-xl border border-gray-200 overflow-hidden">
          {loading ? (
            <div className="flex items-center justify-center py-16 gap-2 text-gray-500">
              <div className="w-5 h-5 border-2 border-blue-600 border-t-transparent rounded-full animate-spin" />
              Loading…
            </div>
          ) : users.length === 0 ? (
            <p className="text-center py-16 text-gray-400 text-sm">No users found.</p>
          ) : (
            <table className="w-full text-sm">
              <thead className="bg-gray-50 border-b border-gray-200">
                <tr>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Name</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Email</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Role</th>
                  <th className="text-left px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Status</th>
                  <th className="text-right px-6 py-3 text-xs font-semibold text-gray-500 uppercase tracking-wider">Actions</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100">
                {users.map((u) => {
                  const isActive = u.status === "active";
                  const isToggling = togglingId === u.id;
                  const isSelf = u.id === user?.id;

                  return (
                    <tr key={u.id} className="hover:bg-gray-50">
                      <td className="px-6 py-4">
                        <div className="flex items-center gap-3">
                          <div
                            className={`w-8 h-8 rounded-full flex items-center justify-center font-semibold text-sm ${
                              isActive
                                ? "bg-blue-100 text-blue-700"
                                : "bg-gray-100 text-gray-400"
                            }`}
                          >
                            {u.name[0].toUpperCase()}
                          </div>
                          <span className={`font-medium ${isActive ? "text-gray-900" : "text-gray-400"}`}>
                            {u.name}
                          </span>
                        </div>
                      </td>
                      <td className={`px-6 py-4 ${isActive ? "text-gray-600" : "text-gray-400"}`}>
                        {u.email}
                      </td>
                      <td className="px-6 py-4">
                        <span
                          className={`text-xs font-medium px-2.5 py-1 rounded-full capitalize ${
                            u.role === "admin"
                              ? "bg-purple-100 text-purple-700"
                              : "bg-gray-100 text-gray-600"
                          }`}
                        >
                          {u.role}
                        </span>
                      </td>

                      {/* ── Status column ── */}
                      <td className="px-6 py-4">
                        <button
                          type="button"
                          onClick={() => handleToggleStatus(u)}
                          disabled={isToggling || isSelf}
                          title={isSelf ? "Cannot change your own status" : undefined}
                          className={`inline-flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border transition-colors disabled:opacity-50 disabled:cursor-not-allowed ${
                            isActive
                              ? "bg-green-50 border-green-200 text-green-700 hover:bg-green-100"
                              : "bg-red-50 border-red-200 text-red-600 hover:bg-red-100"
                          }`}
                        >
                          {isToggling ? (
                            <span className="w-3 h-3 border border-current border-t-transparent rounded-full animate-spin" />
                          ) : (
                            <span
                              className={`w-1.5 h-1.5 rounded-full ${
                                isActive ? "bg-green-500" : "bg-red-400"
                              }`}
                            />
                          )}
                          {isActive ? "Active" : "Inactive"}
                        </button>
                      </td>

                      <td className="px-6 py-4 text-right space-x-3">
                        <button
                          type="button"
                          onClick={() => startEdit(u)}
                          className="text-blue-600 hover:underline text-xs font-medium"
                        >
                          Edit
                        </button>
                        <button
                          type="button"
                          onClick={() => handleDeleteUser(u)}
                          disabled={deletingId === u.id || isSelf}
                          className="text-red-500 hover:underline text-xs font-medium disabled:opacity-40 disabled:no-underline"
                        >
                          {deletingId === u.id ? "Deleting..." : "Delete"}
                        </button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
        </div>

        {/* Edit modal */}
        {editingId && (
          <div className="fixed inset-0 bg-black/30 flex items-center justify-center p-4 z-50">
            <div className="bg-white rounded-xl border border-gray-200 shadow-xl w-full max-w-lg">
              <div className="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h2 className="font-semibold text-gray-800">Edit User</h2>
                <button
                  type="button"
                  onClick={cancelEdit}
                  className="text-sm text-gray-500 hover:text-gray-700"
                >
                  Close
                </button>
              </div>
              <form onSubmit={handleUpdateUser} className="p-6 space-y-3">
                <input
                  value={editName}
                  onChange={(e) => setEditName(e.target.value)}
                  placeholder="Full name"
                  required
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  type="email"
                  value={editEmail}
                  onChange={(e) => setEditEmail(e.target.value)}
                  placeholder="Email"
                  required
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <input
                  type="password"
                  value={editPassword}
                  onChange={(e) => setEditPassword(e.target.value)}
                  placeholder="New password (leave blank to keep current)"
                  className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                />
                <div className="grid grid-cols-2 gap-3">
                  <select
                    value={editRole}
                    onChange={(e) => setEditRole(e.target.value as "sales" | "admin")}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  >
                    <option value="sales">Sales</option>
                    <option value="admin">Admin</option>
                  </select>

                  {/* Status dropdown in edit modal */}
                  <select
                    value={editStatus}
                    onChange={(e) => setEditStatus(e.target.value as "active" | "inactive")}
                    disabled={editingId === user?.id}
                    title={editingId === user?.id ? "Cannot change your own status" : undefined}
                    className="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                  >
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </select>
                </div>

                <div className="flex justify-end gap-3 pt-3">
                  <button
                    type="button"
                    onClick={cancelEdit}
                    className="px-4 py-2 text-sm border border-gray-300 rounded-lg hover:bg-gray-50"
                  >
                    Cancel
                  </button>
                  <button
                    type="submit"
                    disabled={savingId === editingId}
                    className="bg-blue-600 hover:bg-blue-700 disabled:bg-blue-400 text-white text-sm font-medium px-4 py-2 rounded-lg transition-colors"
                  >
                    {savingId === editingId ? "Saving..." : "Save Changes"}
                  </button>
                </div>
              </form>
            </div>
          </div>
        )}
      </div>
    </Layout>
  );
}
