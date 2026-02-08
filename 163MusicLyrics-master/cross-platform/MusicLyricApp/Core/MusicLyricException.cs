using System;
using System.Runtime.Serialization;

namespace MusicLyricApp.Core;

[Serializable]
public class MusicLyricException : Exception
{
    public MusicLyricException()
    {
    }

    public MusicLyricException(string message) : base(message)
    {
    }

    public MusicLyricException(string message, Exception inner) : base(message, inner)
    {
    }

    // A constructor is needed for serialization when an
    // exception propagates from a remoting server to the client.
    protected MusicLyricException(SerializationInfo info,
        StreamingContext context) : base(info, context)
    {
    }
}