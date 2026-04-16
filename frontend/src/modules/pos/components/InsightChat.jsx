export default function InsightChat({ chatHistory }) {
  if (chatHistory.length === 0) {
    return null;
  }

  return (
    <div className="bg-slate-900/60/80 border border-indigo-500/30 p-5 rounded-2xl flex flex-col gap-4 max-h-[400px] overflow-y-auto w-full mx-auto">
      {chatHistory.map((msg, i) => (
        <div
          key={i}
          className={`p-4 rounded-2xl text-sm max-w-[90%] sm:max-w-[75%] shadow-sm ${msg.role === "user" ? "bg-indigo-600 text-slate-100 self-end ml-auto rounded-tr-sm" : "bg-slate-800/40 border border-slate-700/50 text-slate-200 self-start rounded-tl-sm"}`}
        >
          <p className="whitespace-pre-wrap leading-relaxed">{msg.content}</p>
        </div>
      ))}
    </div>
  );
}
